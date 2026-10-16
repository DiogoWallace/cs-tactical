# Convenções da API

- Base: `/api/v1`. Mudanças incompatíveis geram `/api/v2`.
- Recursos no plural, kebab-case: `/training-sessions`, `/teams/{team}/members`.
- Recursos de equipe sempre aninhados sob `/teams/{team}` (com scoped bindings).
- JSON em `snake_case`; datas ISO 8601 em UTC; IDs como string UUID.
- Sempre enviar `Accept: application/json`.

## Respostas

```json
{ "data": { "id": "0199f1c2-...", "name": "..." } }
```

Listas paginadas:

```json
{ "data": [ ... ], "links": { ... }, "meta": { "current_page": 1, "per_page": 20, "total": 42 } }
```

## Erros

Toda resposta de erro tem o mesmo formato, aplicado por `app/Support/Http/ApiErrorResponse.php` a qualquer rota `/api/*` ou requisição que espera JSON:

```json
{ "message": "Texto em inglês, para log e depuração", "code": "codigo_estavel" }
```

- `code` é estável e é o que o frontend usa para decidir o comportamento e o texto exibido. `message` não deve ser mostrada diretamente ao usuário (exceto em 403 e 422, onde é escrita pela própria aplicação).
- `errors` aparece apenas em 422.
- Com `APP_DEBUG=true`, erros 5xx incluem também `exception`, `file`, `line` e `trace`. Erros 4xx nunca incluem.

| Status | `code` | `message` | Observação |
|---|---|---|---|
| 400 | `bad_request` | original | |
| 401 | `unauthenticated` | `Unauthenticated.` | sem sessão; o frontend redireciona ao login |
| 403 | `forbidden` | a da Policy/Gate, ou `This action is unauthorized.` | |
| 403 | `email_not_verified` | `Your email address is not verified.` | rota que exige e-mail confirmado (`EnsureEmailIsVerified`) |
| 403 | `frontend_session_required` | `This endpoint is only available to the web application.` | login, cadastro e logout chamados sem a sessão do frontend |
| 404 | `not_found` | `Not found.` | fixa: não expõe rota nem classe do model; inclui recurso de outra equipe |
| 405 | `method_not_allowed` | `Method not allowed.` | |
| 409 | `conflict` | original | |
| 409 | `already_authenticated` | `Already authenticated.` | cadastro ou login com sessão ativa |
| 413 | `payload_too_large` | `Payload too large.` | |
| 419 | `csrf_token_mismatch` | `CSRF token mismatch.` | o `apiClient` renova o token e repete uma vez |
| 422 | `validation_failed` | original | `errors: { campo: [mensagens] }` |
| 429 | `too_many_requests` | `Too Many Attempts.` | mantém o header `Retry-After` |
| 503 | `service_unavailable` | `Service Unavailable` | |
| outros 5xx | `server_error` | `Server Error` | sem detalhes com `APP_DEBUG=false` |
| outros 4xx | `http_error` | original | |

Erros de regra de negócio usam `code` próprio, estável e legível por máquina (ex.: `team_member_limit_reached`). Quando a resposta já traz um `code`, ele é mantido.

O health check (`/api/v1/health`) não é afetado: o `503` dele é uma resposta normal, com formato próprio.

## Endpoints atuais

| Método | Caminho | Auth | Descrição |
|---|---|---|---|
| GET | `/up` | não | Liveness do Laravel (processo vivo) |
| GET | `/api/v1/health` | não | Readiness: verifica PostgreSQL e Redis |
| POST | `/api/v1/auth/register` | não (sessão do frontend + CSRF) | Cadastro; inicia a sessão |
| POST | `/api/v1/auth/login` | não (sessão do frontend + CSRF) | Login |
| POST | `/api/v1/auth/logout` | sim (+ CSRF) | Logout |
| GET | `/api/v1/me` | sim | Usuário da sessão |
| POST | `/api/v1/auth/forgot-password` | não (sessão do frontend + CSRF) | Pede o link de redefinição de senha |
| POST | `/api/v1/auth/reset-password` | não (sessão do frontend + CSRF) | Redefine a senha com o token do link |
| GET | `/api/v1/auth/email/verify/{id}/{hash}?expires&signature` | sim + assinatura | Confirma o e-mail |
| POST | `/api/v1/auth/email/verification-notification` | sim (+ CSRF) | Reenvia o link de confirmação |

### `POST /api/v1/auth/register`

Corpo:

```json
{
  "name": "Ana Souza",
  "email": "ana@example.com",
  "password": "...",
  "password_confirmation": "...",
  "nickname": "AnaAWP",
  "country_code": "BR",
  "timezone": "America/Sao_Paulo"
}
```

| Campo | Regras |
|---|---|
| `name` | obrigatório, até 120 caracteres |
| `email` | obrigatório, e-mail válido (RFC), até 254, único sem diferenciar maiúsculas; gravado em minúsculas |
| `password` | obrigatório, 8 a 72 caracteres, igual a `password_confirmation` |
| `nickname` | obrigatório, 3 a 24 caracteres `[A-Za-z0-9_.-]`, único sem diferenciar maiúsculas |
| `country_code` | opcional, ISO 3166-1 alfa-2 (aceita minúsculas e converte) |
| `timezone` | opcional, identificador IANA (ex.: `America/Sao_Paulo`); padrão `UTC` |

Qualquer outro campo (`platform_role`, `email_verified_at`, `id`...) é ignorado; o papel inicial é sempre `user`. Resposta **201** com o usuário, como em `/me`. Erros: 422 (validação, inclusive e-mail ou apelido em uso), 409, 403, 419, 429 (3/min por IP).

### `POST /api/v1/auth/login`

Corpo: `{ "email", "password", "remember"? }`. Resposta **200** com o usuário, como em `/me`. Credenciais inválidas: **422** com erro em `email`, a mesma resposta para senha errada e e-mail inexistente. Limite: 5/min por e-mail + IP real.

### `POST /api/v1/auth/logout`

Sem corpo. Resposta **204**. Invalida a sessão e troca o token CSRF (o cookie `XSRF-TOKEN` vem renovado na resposta).

### `GET /api/v1/me`

```json
{
  "data": {
    "id": "01a14322-df0e-7031-ba00-dfdf62045361",
    "name": "Ana Souza",
    "email": "ana@example.com",
    "email_verified": false,
    "platform_role": "user",
    "profile": { "nickname": "AnaAWP", "country_code": "BR", "timezone": "America/Sao_Paulo", "bio": null },
    "created_at": "2026-10-16T05:12:30Z"
  }
}
```

Lista fechada de campos (`UserResource`): senha, `remember_token` e datas internas nunca saem. Sem sessão: 401. Limite: 120/min por usuário.

### `GET /api/v1/health`

Verifica PostgreSQL (`select 1`) e Redis (`PING`). Responde `200` quando tudo está ok e `503` quando alguma dependência falha.

```json
{
  "status": "ok",
  "timestamp": "2026-10-16T02:54:58+00:00",
  "checks": {
    "database": { "status": "ok", "latency_ms": 12.27 },
    "redis": { "status": "ok", "latency_ms": 2.09 }
  }
}
```

O bloco `checks` só aparece quando `HEALTH_EXPOSE_DETAILS=true` (padrão apenas em `APP_ENV=local`). Mensagens de erro nunca vão para a resposta; ficam no log da API.

### `POST /api/v1/auth/forgot-password`

Corpo: `{ "email" }`. Resposta **200** `{ "message": "Se houver uma conta com este e-mail, enviaremos um link para redefinir a senha." }`, igual para e-mail cadastrado, desconhecido ou pedido repetido. O link enviado é `FRONTEND_URL/redefinir-senha#token=...&email=...` e vale por 60 minutos. Erros: 422 (formato do e-mail), 409, 429 (5/min por e-mail + IP).

### `POST /api/v1/auth/reset-password`

Corpo: `{ "token", "email", "password", "password_confirmation" }`. Resposta **200** `{ "message" }`; não faz login. Token inválido, expirado, já usado ou de outra conta: **422** com a mesma mensagem em `errors.token`. Senha fora das regras: 422 em `errors.password`, e o token continua válido. Ao redefinir, as outras sessões e os cookies "lembrar-me" deixam de valer.

### `GET /api/v1/auth/email/verify/{id}/{hash}`

Chamado pela página `/verificar-email/{id}/{hash}?expires=...&signature=...` do frontend, com o mesmo caminho e a mesma query. Resposta **204** (também se já estava confirmado). Assinatura inválida, adulterada, expirada (60 minutos) ou link de outra conta: **403** `forbidden`. Sem sessão: 401. Limite: 6/min por usuário.

### `POST /api/v1/auth/email/verification-notification`

Sem corpo. **202** quando envia um novo link; **204** quando o e-mail já está confirmado (nada é enviado). Limite: 6/min por usuário.
