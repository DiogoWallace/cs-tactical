# 0010 — Laravel Fortify headless sob `/api/v1/auth`

- Status: Aceita (login, logout e cadastro na etapa 1.2; recuperação de senha e verificação de e-mail na 1.4)
- Data: 2026-10-16

## Contexto

A [ADR 0003](0003-autenticacao-sanctum-sessao-mesma-origem.md) definiu o Fortify em modo headless para os fluxos de identidade. Faltava decidir onde as rotas ficam, como elas convivem com o monólito modular e com o proxy de mesma origem, e como tratar os pontos em que o comportamento padrão do Fortify não atende às decisões de produto da Fase 1.

## Decisão

### Instalação e rotas

- `laravel/fortify` 1.41, compatível com o Laravel 13 (`illuminate/support ^11|^12|^13`, verificado na instalação, [ADR 0002](0002-laravel-13-e-php-84.md)). O pacote traz `laravel/passkeys` como dependência; o próprio Fortify desliga as rotas dele, e nenhuma migration é carregada automaticamente.
- **As rotas do pacote ficam desligadas (`Fortify::ignoreRoutes()`).** Com elas ligadas, o Fortify registraria sempre `/user/confirm-password` e `/user/confirmed-password-status`, mesmo sem uso. O módulo Identity declara em `routes.php` apenas os endpoints decididos, usando os controllers do Fortify:
  - `POST /api/v1/auth/register` — `RegisteredUserController@store`;
  - `POST /api/v1/auth/login` — `AuthenticatedSessionController@store`;
  - `POST /api/v1/auth/logout` — `AuthenticatedSessionController@destroy`.

  Os próximos endpoints (recuperação de senha e verificação de e-mail na etapa 1.4; alteração de senha na 1.5) entram da mesma forma.
- `config/fortify.php`: `views => false`, `guard => 'web'`, `username => 'email'`, `lowercase_usernames => true`, `limiters.login => 'login'`. `features` liga apenas o que já tem rota (`registration` na etapa 1.2). `prefix` e `middleware` ficam documentados, mas só teriam efeito nas rotas do pacote.
- As rotas usam o grupo `api`, que contém o middleware stateful do Sanctum (`statefulApi()`): recebem sessão e verificação CSRF como o resto da API.
- **Só requisições do frontend.** `EnsureFrontendSession` responde 403 (`frontend_session_required`) quando a requisição não tem sessão (origem fora de `SANCTUM_STATEFUL_DOMAINS` ou sem `Origin`/`Referer`). Sem isso, o login de um cliente sem sessão gravaria uma sessão órfã no Redis.
- **Cadastro e login exigem não estar autenticado.** `RejectAuthenticated` responde 409 (`already_authenticated`), em vez do redirecionamento do middleware `guest`, que não serve a um cliente JSON.
- **Sem tokens.** `Sanctum::getAccessTokenFromRequestUsing(fn () => null)`: o header `Authorization: Bearer` é ignorado. Sem isso, o guard do Sanctum consultaria a tabela `personal_access_tokens`, que não existe (ADR 0003), e responderia 500.
- `GET /api/v1/me` **não** é do Fortify: pertence ao módulo Identity (`auth:sanctum`, resposta via `UserResource`).

### Organização no monólito modular

- A integração vive em `app/Modules/Identity`: as classes que o Fortify pede (`CreateNewUser`, `ResetUserPassword`, `UpdateUserPassword`) ficam em `Actions/`, e o `IdentityServiceProvider` faz o registro (`Fortify::createUsersUsing(...)` e similares), as respostas customizadas e os rate limiters.
- O `FortifyServiceProvider` publicado pelo instalador não é usado: a configuração fica no provider do módulo.

### Respostas

- Toda resposta é JSON (as requisições sempre enviam `Accept: application/json`). Erros seguem o formato padronizado `{ message, code, errors? }` ([convenções](../api/convencoes.md#erros)).
- Cadastro responde **201** e login **200**, ambos com `{ data: <usuário> }` no mesmo formato de `GET /api/v1/me` (respostas `LoginResponse` e `RegisterResponse` do módulo). Logout responde **204**.
- O login usa `guard->attempt()` do Laravel, sem `Fortify::authenticateUsing()`: o `SessionGuard` executa a tentativa dentro de um *timebox* (200 ms), então o tempo de resposta não revela se o e-mail existe.
- Login inválido responde **422** com erro em `email` (comportamento do Fortify), nunca 401: 401 significa "sem sessão" e dispara o redirecionamento do frontend.
- Cadastro com e-mail já existente responde **422** (decisão de produto: o cadastro pode revelar que o e-mail existe).
- **Recuperação de senha não revela se o e-mail existe**: `POST /forgot-password` responde sempre 200 com a mesma mensagem. O Fortify, por padrão, devolve 422 para e-mail desconhecido. Isso será trocado por uma resposta própria (`FailedPasswordResetLinkRequestResponse` ligado à mesma resposta de sucesso), com teste.
- Após o login, a sessão é regenerada (Fortify); após o logout, a sessão é invalidada e o token CSRF é regenerado.

### Links enviados por e-mail

- **Redefinição de senha:** o link aponta para o frontend, `FRONTEND_URL/redefinir-senha?token=...&email=...` (`ResetPassword::createUrlUsing`). A página do Next envia o token para `POST /api/v1/auth/reset-password`.
- **Verificação de e-mail:** a URL assinada é gerada **relativa** (`URL::temporarySignedRoute(..., absolute: false)`) e prefixada com `FRONTEND_URL`. O navegador abre o link no frontend, que o encaminha pelo proxy de mesma origem, e o Laravel valida a assinatura relativa. A validação não depende do host recebido, então `X-Forwarded-Host` continua sem ser confiável ([ADR 0009](0009-ip-real-do-cliente-e-proxies-confiaveis.md)). Como a rota de verificação do Fortify usa a assinatura absoluta, a rota de verificação será declarada pelo módulo Identity com `signed:relative`. Os detalhes ficam para a etapa 1.4.
- Usuários com e-mail não verificado podem entrar; rotas que exigem verificação usam o middleware `verified`.

### Rate limiting

Os limitadores ficam no `IdentityServiceProvider`, com contadores no Redis e o IP real do cliente (ADR 0009):

| Limitador | Limite | Chave |
|---|---|---|
| `login` (Fortify `limiters.login`) | 5/min | e-mail normalizado + IP |
| `password-reset` | 5/min | e-mail normalizado + IP |
| `register` | 3/min | IP |
| `verification` | 6/min | ID do usuário |
| API autenticada | 120/min | ID do usuário |

## Alternativas consideradas

- **Controllers próprios sem Fortify**: mais código para manter nos mesmos fluxos, com mais chance de errar detalhes como regeneração de sessão e tokens.
- **Rotas do Fortify sem prefixo (`/login`, `/register`)**: ficariam fora de `/api`. O Next.js não as encaminharia e elas colidiriam com as páginas do frontend.
- **Breeze/Jetstream**: trazem frontend próprio, o que não se aplica a um frontend separado em Next.js.

## Consequências

- Os fluxos seguem o comportamento testado do Fortify. Os pontos alterados (recuperação de senha sem revelar o e-mail, verificação com assinatura relativa) são poucos e têm testes próprios.
- Atualizações do Fortify precisam ser verificadas contra essas customizações.
