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
| 404 | `not_found` | `Not found.` | fixa: não expõe rota nem classe do model; inclui recurso de outra equipe |
| 405 | `method_not_allowed` | `Method not allowed.` | |
| 409 | `conflict` | original | |
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
