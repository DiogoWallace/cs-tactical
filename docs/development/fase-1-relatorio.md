# Relatório da Fase 1 — Identidade e área autenticada

Registro de cada etapa: arquivos, decisões, comandos, testes e pendências. Atualizado ao fim de cada etapa.

---

## Etapa 1.0 — Fundação transversal

### Arquivos criados ou modificados

| Arquivo | Conteúdo |
|---|---|
| `compose.yaml` | Rede interna com faixa fixa (`INTERNAL_SUBNET`, IPs dinâmicos em `INTERNAL_IP_RANGE`); container `web` com IP fixo; `TRUSTED_PROXIES` da API = IP do `web` |
| `.env.example` | `INTERNAL_SUBNET`, `INTERNAL_IP_RANGE`, `WEB_INTERNAL_IP` |
| `apps/api/config/trustedproxy.php` | Lista de proxies confiáveis a partir de `TRUSTED_PROXIES`; recusa `*`, `**` e `REMOTE_ADDR` |
| `apps/api/bootstrap/app.php` | `trustProxies` só com `X-Forwarded-For` e `X-Forwarded-Proto`; formatador de erros registrado com `respond()` |
| `apps/api/app/Support/Http/ApiErrorResponse.php` | Corpo de erro padronizado `{ message, code, errors? }` |
| `apps/api/config/sanctum.php`, `apps/api/.env.example` | Origens stateful: `localhost:3000,127.0.0.1:3000` (sem o padrão do Sanctum, que incluía a própria API) |
| `apps/api/phpunit.xml` | `TRUSTED_PROXIES` vazio nos testes (cada teste define os proxies) |
| `apps/api/tests/Feature/Http/TrustedProxiesTest.php` | 22 testes: IP real, falsificação, gateway, cadeia, valor malformado, `Forwarded`/`X-Real-IP`, Host/Proto, topologias de produção e modos de falha, rate limiting, config |
| `apps/api/tests/Feature/Http/ApiErrorResponseTest.php` | 16 testes: 401, 403, 404 (rota e model), 405, 419, 422, 429, 5xx com e sem debug, 503, `code` de negócio, health check |
| `apps/api/tests/Feature/Auth/SanctumSpaFoundationTest.php` | 3 testes: padrão das origens stateful, sessão em `127.0.0.1:3000`, API não é origem stateful |
| `apps/web/src/lib/routes.ts` | `LOGIN_PATH`, `loginUrl(next)` e `safeInternalPath` (contra open redirect) |
| `apps/web/src/lib/api/errors.ts` | Tipo `ApiErrorBody`, `ApiError.code`, `isUnauthenticated` |
| `apps/web/src/lib/api/client.ts` | 401 → login com `next`; opção `redirectOnUnauthenticated: false` |
| `apps/web/src/lib/api/server.ts` | 401 → `redirect('/login')`; repassa `X-Forwarded-For` |
| `apps/web/src/lib/**/*.test.ts` | Testes de 401, `code`, `next` seguro e `X-Forwarded-For` |
| `docs/decisions/0009-...md`, `0010-...md` | ADRs: IP real do cliente (com responsabilidades da borda e modos de falha); Fortify headless sob `/api/v1/auth` |
| `docs/deploy/bloqueios-de-producao.md` | Itens obrigatórios antes do deploy público (P1 a P4) |
| `docs/architecture/autenticacao.md`, `docs/api/convencoes.md`, `docs/architecture/visao-geral.md`, `docs/development/wsl2.md` | Documentação atualizada |

### Decisões técnicas

- **O Next.js não identifica o cliente.** Verificado no código do Next 16.4 e com requisições reais: o rewrite de `/api/*` repassa o `X-Forwarded-For` do cliente sem alteração e não acrescenta o IP da conexão. Por isso a confiança fica restrita ao IP fixo do container `web`, e em produção uma borda deve definir o cabeçalho ([ADR 0009](../decisions/0009-ip-real-do-cliente-e-proxies-confiaveis.md)).
- **IP fixo, não a sub-rede.** O gateway do Docker está na sub-rede e é por onde chega a porta 8000 do host. Confiar na sub-rede permitiria falsificar o IP chamando a API diretamente.
- **`X-Forwarded-Host` não é confiável.** Links de verificação de e-mail usarão assinatura relativa ([ADR 0010](../decisions/0010-fortify-headless-em-api-v1-auth.md)), então o host da requisição não precisa ser confiável.
- **Erros com `code` estável.** O formatador atua depois da renderização do Laravel (`respond()`), então cobre exceções do framework, `abort()`, validação e rate limiting sem um handler por tipo. Mensagens de 404 são fixas para não expor nomes de classes.
- **401 no frontend redireciona ao login.** A página `/login` entra na etapa 1.3; até lá, o redirecionamento leva a um 404.
- **Fortify apenas documentado** nesta etapa (ADR 0010). A instalação fica na 1.2.

### Comandos executados

```bash
make down && make up        # recria a rede com a faixa fixa
make test
make lint
make pnpm c=build
curl http://localhost:3000/api/v1/nao-existe
curl -D - -H "Origin: http://127.0.0.1:3000" http://127.0.0.1:3000/sanctum/csrf-cookie
```

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make test` | ✔ API 62 testes; web 35 testes |
| `make lint` | ✔ Pint, Larastan, ESLint, Prettier, TypeScript |
| Build de produção do web | ✔ |
| Rede recriada | ✔ `web` em `172.31.250.10`, demais containers em `172.31.250.128/25`; `TRUSTED_PROXIES=172.31.250.10` |
| IP via Next sem `X-Forwarded-For` | ✔ `172.31.250.10` (IP do container `web`) |
| IP via Next com `X-Forwarded-For: 198.51.100.7` | ✔ `198.51.100.7` |
| Direto na porta 8000 com `X-Forwarded-For` falsificado | ✔ ignorado (IP do gateway do Docker) |
| Erros reais pelo proxy | ✔ 404 `not_found`, 405 `method_not_allowed`, sem detalhes |
| Sessão a partir de `http://127.0.0.1:3000` | ✔ `XSRF-TOKEN` e `cs_tactical_session` emitidos |
| Health checks (8000, 3000, `127.0.0.1:3000`), `/`, `/status` | ✔ 200 |
| `make doctor` | ✔ |

A verificação de IP de ponta a ponta usou um arquivo PHP temporário em `apps/api/public/api/`, removido em seguida.

### Revisão final do fluxo de IP (antes do commit)

- **Um cliente externo não manipula o IP em produção** desde que a borda siga a ADR 0009 (bloqueios P1 a P3): a borda substitui `X-Forwarded-For` e é o único ponto de entrada, e o Laravel só aceita o cabeçalho vindo de endereços listados.
- **A produção não depende do comportamento do Next.js**: a topologia recomendada encaminha `/api/*` da borda direto para a API. Se o rewrite do Next for usado, uma mudança de comportamento dele só pode fazer os clientes compartilharem um contador, nunca escolher o próprio IP (testado em "degrades to an untrusted hop").
- **Cabeçalhos alternativos** (`Forwarded`, `X-Real-IP`, `Client-IP`, `True-Client-IP`, `CF-Connecting-IP`) são ignorados mesmo vindos do proxy (testado).
- **Bloqueios de produção registrados** em [docs/deploy/bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md).

### Pendências e riscos

- **Ambientes já existentes** precisam de `make down && make up` uma vez para recriar a rede com a faixa fixa. Se `172.31.250.0/24` conflitar com outra rede, altere as três variáveis do `.env` da raiz.
- **`apps/api/.env` local** (não versionado) precisa de `SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000`. No ambiente WSL atual isso já foi ajustado.
- **Desenvolvimento sem borda:** o IP é o que o cliente declarar ao Next.js. Isso é aceitável só localmente; a borda em produção é obrigatória (ADR 0009).
- **`PROJECT_CONTEXT.md` não existe no repositório.** A referência ao Laravel 12 está na ADR 0002, que já registra a migração para o 13.

---

## Etapa 1.1 — Fundação do módulo Identity

### Arquivos criados ou modificados

| Arquivo | Conteúdo |
|---|---|
| `database/migrations/2026_10_16_100000_create_users_table.php` | `users` com UUIDv7, índice único em `lower(email)`, índice em `email`, checks de papel e de campos não vazios |
| `database/migrations/2026_10_16_100100_create_profiles_table.php` | `profiles` 1:1 (FK única com cascade), índice único em `lower(nickname)`, checks de formato do apelido e do país |
| `database/migrations/2026_10_16_100200_create_password_reset_tokens_table.php` | Tabela do broker de senhas |
| `app/Modules/Identity/Enums/PlatformRole.php` | `user`, `editor`, `admin` |
| `app/Modules/Identity/Models/User.php` | `Authenticatable`, `HasUuids`, casts (`hashed`, enum, datas), `$fillable` restrito, e-mail normalizado, `whereEmailIs`, relação `profile` |
| `app/Modules/Identity/Models/Profile.php` | `HasUuids`, `$fillable` sem `user_id`, apelido sem espaços, país em maiúsculas, `whereNicknameIs`, relação `user` |
| `database/factories/Identity/{User,Profile}Factory.php` | Estados `withProfile`, `unverified`, `editor`, `admin`; apelidos válidos para a check constraint |
| `config/auth.php` | Provider `users` com `App\Modules\Identity\Models\User` |
| `app/Providers/AppServiceProvider.php` | `Model::shouldBeStrict()` fora de produção |
| `tests/Pest.php` | `RefreshDatabase` em todos os testes de `Feature` |
| `tests/Feature/System/HealthCheckTest.php` | Restaura a conexão do banco depois dos testes que a derrubam (necessário com `RefreshDatabase`) |
| `tests/Feature/Identity/*` | 43 testes: UUIDv7, casts, normalização, mass assignment, constraints, relação, cascade, configuração de auth, reversão das migrations |
| `tests/Unit/ArchitectureTest.php` | Regras do Identity e proibição do namespace antigo `App\Models` |
| `docs/database/modelo-conceitual.md` | Esquema implementado |

### Decisões técnicas

- **Unicidade sem diferenciar maiúsculas por índice funcional (`lower(...)`)**, não pela extensão `citext`: não exige `CREATE EXTENSION` em nenhum ambiente e a regra fica visível no esquema. O e-mail também é gravado normalizado, para que buscas por igualdade usem um índice comum; o apelido mantém a grafia escolhida pelo jogador.
- **Regras de formato também no banco** (papel, apelido, país), seguindo o princípio de constraints do modelo conceitual. Mudar uma regra exige migration, e o enum `PlatformRole` documenta isso.
- **`platform_role`, `email_verified_at`, `id` e `user_id` fora do `$fillable`.** Com `Model::shouldBeStrict()` fora de produção, passar um deles em massa lança exceção; em produção, o valor é descartado e nunca aplicado (os dois comportamentos têm teste).
- **Factories com `#[UseFactory]`**, em `database/factories/Identity`: o nome padrão que o Laravel deduziria para models em `App\Modules` seria `Database\Factories\Modules\Identity\Models\...`.
- **Usuário da factory sem perfil por padrão** (`withProfile()` para o caso real): testes de constraints precisam de usuários sem perfil. A garantia de que toda conta tem perfil será da Action de cadastro (etapa 1.2), numa transação.
- **Sem `IdentityServiceProvider` ainda**: o módulo não tem rotas, policies nem comandos. O provider nasce na etapa 1.2, com o Fortify (regra 1 da visão geral: nada vazio).
- **`MustVerifyEmail` fica para a etapa 1.4**, junto com a verificação de e-mail.
- **`RefreshDatabase` global em `Feature`**, como previsto no `tests/Pest.php` da Fase 0. Os testes do health check que derrubam a conexão do banco passaram a restaurá-la antes do rollback.

### Comandos executados

```bash
make migrate
docker compose exec api php artisan migrate:rollback --step=3
docker compose exec api php artisan migrate
make test
make lint
```

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make migrate` | ✔ três migrations aplicadas no banco de desenvolvimento |
| Rollback e reaplicação no banco de dev | ✔ `migrate:rollback --step=3` e `migrate` sem erro |
| Esquema (`\d users`, `\d profiles`) | ✔ `uuid`, índices `lower(...)`, checks e FK com `ON DELETE CASCADE` |
| `make test` | ✔ API 108 testes; web 35 testes |
| `make lint` | ✔ Pint, Larastan nível 6, ESLint, Prettier, TypeScript |
| Health check pelo proxy | ✔ 200 |

### Pendências

- Cadastro com criação atômica de usuário + perfil, login, logout e `/me`: etapa 1.2.
- Validação do fuso horário (IANA) e do país na aplicação: etapas 1.2 e 1.5.

---

## Etapa 1.2 — Autenticação e sessões

### Arquivos criados ou modificados

| Arquivo | Conteúdo |
|---|---|
| `composer.json`, `composer.lock` | `laravel/fortify` ^1.41 |
| `config/fortify.php` | Headless: sem views, guard `web`, e-mail em minúsculas, limitador `login`, só `registration` ligado |
| `app/Modules/Identity/IdentityServiceProvider.php` | `Fortify::ignoreRoutes()`, `createUsersUsing`, respostas de login/cadastro, `Password::defaults()` (8 a 72), Sanctum sem tokens, limitadores `login`, `register` e `api`, rotas do módulo |
| `app/Modules/Identity/routes.php` | `POST /auth/register`, `POST /auth/login`, `POST /auth/logout`, `GET /me` |
| `app/Modules/Identity/Actions/CreateNewUser.php` | Validação e criação de `User` + `Profile` numa transação; corrida de unicidade vira 422 |
| `app/Modules/Identity/Support/CountryCodes.php` | 249 códigos ISO 3166-1 alfa-2 |
| `app/Modules/Identity/Http/Middleware/EnsureFrontendSession.php` | 403 sem a sessão do frontend |
| `app/Modules/Identity/Http/Middleware/RejectAuthenticated.php` | 409 para cadastro/login com sessão ativa |
| `app/Modules/Identity/Http/Controllers/MeController.php` | `GET /api/v1/me` |
| `app/Modules/Identity/Http/Resources/{User,Profile}Resource.php` | Lista fechada de campos |
| `app/Modules/Identity/Http/Responses/{Login,Register}Response.php` | 200/201 com o usuário |
| `bootstrap/providers.php` | `IdentityServiceProvider` |
| `bootstrap/app.php` | `redirectGuestsTo(fn () => null)`: 401 em JSON sem depender de uma rota `login` |
| `tests/Support/FrontendSession.php` | Simula o navegador: cookies, Origin, `X-XSRF-TOKEN`, estado limpo entre requisições |
| `tests/Feature/Identity/RegistrationTest.php` | 26 testes |
| `tests/Feature/Identity/SessionAuthenticationTest.php` | 21 testes (CSRF real e sessões no Redis) |
| `tests/Unit/ArchitectureTest.php` | Regras de controllers e Actions do Identity |
| `docs/decisions/0010-...md`, `docs/api/convencoes.md`, `docs/architecture/autenticacao.md` | Decisões e endpoints |

### Decisões técnicas

- **Rotas do Fortify desligadas, declaradas pelo módulo** (ADR 0010 atualizada). Com as rotas do pacote, `/user/confirm-password` e `/user/confirmed-password-status` ficariam expostas sem uso. `route:list` mostra apenas os quatro endpoints novos, sem duplicatas.
- **Respostas de login e cadastro com o usuário** (mesmo formato de `/me`): o frontend não precisa de uma segunda chamada.
- **Login sem `authenticateUsing`**: o `guard->attempt()` do Laravel usa um *timebox* de 200 ms, então o tempo de resposta também não revela se o e-mail existe.
- **Bearer desligado no Sanctum.** Os testes mostraram que `Authorization: Bearer ...` causava 500 (consulta à tabela `personal_access_tokens`, que não existe). Agora é 401.
- **`EnsureFrontendSession`**: sem ele, um login sem `Origin` do frontend autenticaria numa sessão que nunca chega ao cliente.
- **`RejectAuthenticated` (409)** no lugar do middleware `guest`, que redirecionaria.
- **Corrida no cadastro**: dois cadastros simultâneos com o mesmo e-mail ou apelido passam pela validação, mas o índice único do banco barra um deles; a exceção vira o mesmo 422 de campo.
- **Senha de 8 a 72 caracteres**: o bcrypt ignora o que passa de 72 bytes.
- **País por lista no código**: a extensão `intl` não está instalada.

### Verificação pelo proxy (Next.js → Laravel, Postgres e Redis reais)

| Fluxo | Resultado |
|---|---|
| Cadastro com `platform_role=admin` forjado | ✔ 201, papel `user`, sessão regenerada |
| `/me` após cadastro | ✔ 200 |
| Cadastro já autenticado | ✔ 409 `already_authenticated` |
| Logout sem `X-XSRF-TOKEN` | ✔ 419 |
| Logout | ✔ 204, token CSRF trocado |
| `/me` após logout e com o cookie de sessão antigo | ✔ 401 e 401 |
| Senha errada × e-mail inexistente | ✔ 422, respostas idênticas |
| Login sem `X-XSRF-TOKEN` | ✔ 419 |
| Login com e-mail em maiúsculas | ✔ 200, sessão regenerada |
| Campos de `/me` | ✔ `id, name, email, email_verified, platform_role, profile{nickname, country_code, timezone, bio}, created_at` |
| `Bearer` sem cookie | ✔ 401 |
| Cookie válido enviado de outra origem | ✔ 401 |
| Login direto na porta 8000, sem `Origin` | ✔ 403 `frontend_session_required` |
| Método errado / rota do pacote fora do escopo | ✔ 405 / 404 |
| 6 tentativas de login | ✔ 422 ×5, depois 429 |
| Banco | ✔ usuário e perfil gravados (`user`, não verificado, país e fuso); usuário de teste removido depois |

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make migrate` | ✔ nada pendente (a etapa não cria tabelas) |
| `make test` | ✔ API 157 testes; web 35 testes |
| `make lint` | ✔ Pint, Larastan nível 6, ESLint, Prettier, TypeScript |
| Build de produção do web | ✔ |

### Riscos e pendências

- **Mensagens da API em inglês.** Não há tradução `pt_BR` (`lang/`); o frontend deve exibir textos a partir de `code` e dos campos de `errors`. A tradução das mensagens de validação pode entrar na etapa 1.3.
- **Política de senha mínima** (8 a 72). Verificação contra senhas vazadas (`Password::uncompromised()`, consulta externa) não foi ligada; avaliar antes do deploy público.
- **Limite de cadastro por IP (3/min)** pode afetar usuários atrás do mesmo NAT e os testes E2E da etapa 1.7.
- **Apelidos reservados** (`admin`, `suporte`...) não são bloqueados.
- **Dependências do Fortify sem uso** (`laravel/passkeys`, `pragmarx/google2fa`, `bacon/bacon-qr-code`) entram no `vendor`. As rotas e migrations delas não são carregadas.
- **Observação fora do escopo:** `route:list` mostra `GET|PUT storage/{path}`, rotas do disco local do Laravel existentes desde a Fase 0 (`serve` no `config/filesystems.php`). Revisar quando houver upload de arquivos.

### Revisão de segurança antes do merge (PR #1)

| Achado | Correção |
|---|---|
| Login com `email` em formato de lista ou objeto respondia 500: o limitador de login convertia o valor para texto antes da validação | `EnsureStringFields` responde 422 para campos de texto que chegam em outro formato; o limitador só usa o e-mail quando é texto |
| Cadastro com campos em formato de lista respondia 500: o controller do Fortify põe o e-mail em minúsculas antes da validação | Mesmo middleware, antes do controller |
| Cookie "lembrar-me" valia 400 dias (padrão do Laravel) | `remember => 43200` no guard `web`: 30 dias |

Testes novos: 3 de login com campos que não são texto, 1 de cadastro e 3 do "lembrar-me" (validade de 30 dias, ausência sem `remember` e remoção no logout). `make test`: API 164, web 35.

---

## Etapa 1.3 — Frontend autenticado

Branch `feat/phase-1-auth-frontend`, criada da `main` depois do merge do PR #1 (etapas 1.0 a 1.2). A branch `feat/phase-1-foundation` foi apagada (local e remota); `backup/phase-0-original` continua preservada.

### Arquivos criados ou modificados

| Arquivo | Conteúdo |
|---|---|
| `apps/web/src/proxy.ts` | Redireciona ao login sem cookie de sessão na área logada; repassa `x-pathname` |
| `apps/web/src/lib/routes.ts` | `REGISTER_PATH`, `HOME_PATH`, `PROTECTED_PATH_PREFIXES`, `isProtectedPath`, `afterLoginPath` |
| `apps/web/src/lib/session.ts` | Nome do cookie de sessão e do header `x-pathname` |
| `apps/web/src/lib/api/server.ts` | 401 redireciona para `/login?next=<página atual>` |
| `apps/web/src/app/layout.tsx` | Só `<html>`, `<body>` e tema; cabeçalho e `<main>` passam para os grupos |
| `apps/web/src/app/(marketing)/` | `layout.tsx` com `SiteHeader`; `page.tsx` e `status/` movidos para cá |
| `apps/web/src/app/(auth)/` | `layout.tsx`, `login/page.tsx`, `cadastro/page.tsx` |
| `apps/web/src/app/(app)/` | `layout.tsx` (cabeçalho com usuário e sair), `painel/page.tsx` |
| `apps/web/src/features/identity/` | `types.ts`; `api/` (`getCurrentUser`, `findCurrentUser`, `login`, `register`, `logout`); `components/` (formulários, campo com erro acessível, cabeçalho, botão sair, redirecionamento de quem já está logado); `lib/` (países, fuso, erros de formulário, navegação) |
| `apps/web/src/components/ui/{input,label}.tsx` | Componentes shadcn/ui |
| `apps/web/src/components/site-header.tsx`, `app/(marketing)/page.tsx`, `app/not-found.tsx` | Links de entrar/criar conta; `<main>` na página 404 |
| `apps/api/lang/pt_BR/{validation,auth}.php`, `apps/api/lang/pt_BR.json` | Mensagens de validação e de login em português (só as regras usadas) |
| Testes | Web: `proxy.test.ts`, `routes.test.ts`, `server.test.ts`, `form-errors.test.ts`, `login-form.test.tsx`, `register-form.test.tsx`, `logout-button.test.tsx`. API: mensagens em português no cadastro e no login |

### Decisões técnicas

- **URLs em português** (`/cadastro`, `/painel`), como a interface; `/login` mantido.
- **Mensagens da API traduzidas no backend** (`lang/pt_BR`), não no frontend: os erros de campo já chegam prontos em `errors`. Mensagens gerais (429, 419, conexão) vêm do `code` (`features/identity/lib/form-errors.ts`).
- **Credenciais inválidas como mensagem geral** no login (a API devolve o erro em `email`, mas não diz qual campo errou).
- **Lista de países e fuso só no navegador** (`useSyncExternalStore` com valor vazio no servidor). Os nomes vêm do `Intl.DisplayNames`, e os dados de idioma do Node e do navegador diferem (ex.: "Hong Kong, RAE da China" × "Hong Kong"): renderizar no servidor causou erro de hidratação na validação.
- **Navegação completa depois de mudar a sessão** (ver docs/architecture/autenticacao.md).
- **"Lembrar-me"** aparece como "Manter conectado por 30 dias".
- **Dashboard mínimo**: saudação e dados da conta. Navegação completa, indicação de Academy e Teams e E2E com Playwright ficam na etapa 1.7.

### Validação no navegador (Next dev + API + Postgres + Redis reais)

| Fluxo | Resultado |
|---|---|
| `/painel` sem sessão | ✔ redireciona para `/login?next=%2Fpainel` (`proxy.ts`) |
| Cadastro com dados inválidos | ✔ erros em português, cada um no seu campo |
| Cadastro válido (país Brasil, fuso detectado `America/Sao_Paulo`) | ✔ vai ao painel com apelido, e-mail "Não verificado" e fuso |
| Sair | ✔ volta ao `/login`; `/painel` em seguida redireciona ao login (cookie de visitante → `/me` 401 → `next`) |
| Login com senha errada | ✔ alerta "E-mail ou senha incorretos." |
| Login correto com `?next=/painel` | ✔ volta ao painel com cabeçalho do usuário |
| `/login` já autenticado | ✔ redireciona ao painel |
| Celular (375 px) | ✔ painel e início sem rolagem horizontal |
| Console | ✔ sem erros além do 422 esperado da senha errada |

O usuário criado no teste manual foi removido do banco de desenvolvimento.

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make test` | ✔ API 166 testes; web 67 testes |
| `make lint` | ✔ Pint, Larastan, ESLint, Prettier, TypeScript |
| Build de produção | ✔ `/login`, `/cadastro`, `/painel` e `/status` como Partial Prerender; `proxy.ts` registrado |

### Pendências e riscos

- **Mensagens em português só para as regras usadas hoje.** Uma regra nova sem tradução aparece em inglês; o comentário de `lang/pt_BR/validation.php` registra isso.
- **Lista de países duplicada** (PHP e TypeScript). Se mudar, precisa mudar nos dois.
- **`matcher` do `proxy.ts` e `PROTECTED_PATH_PREFIXES`** precisam ser atualizados juntos ao criar uma rota na área logada (há teste para o `matcher` atual).
- **E2E automatizado** (Playwright) fica para a etapa 1.7; a validação desta etapa no navegador foi manual.

---

## Etapa 1.4 — Segurança da conta

Branch `feat/phase-1-auth-frontend` (mesma da etapa 1.3).

### Arquivos criados ou modificados

| Arquivo | Conteúdo |
|---|---|
| `config/fortify.php` | `resetPasswords` e `emailVerification` ligados |
| `config/app.php` | `frontend_url` (de `FRONTEND_URL`) |
| `app/Modules/Identity/routes.php` | `forgot-password`, `reset-password`, `email/verify/{id}/{hash}` (assinatura relativa), `email/verification-notification` |
| `app/Modules/Identity/IdentityServiceProvider.php` | `resetUserPasswordsUsing`, links dos e-mails para o frontend, respostas de senha, limitadores `password-reset` e `verification` |
| `app/Modules/Identity/Models/User.php` | `MustVerifyEmail`; notificações próprias |
| `app/Modules/Identity/Notifications/{VerifyEmail,ResetPassword}Notification.php` | Enviadas pela fila, depois do commit |
| `app/Modules/Identity/Actions/ResetUserPassword.php` | Valida e grava a nova senha |
| `app/Modules/Identity/Http/Responses/PasswordResetLinkResponse.php` | 200 igual para qualquer e-mail |
| `app/Modules/Identity/Http/Responses/FailedPasswordResetResponse.php` | Uma única mensagem para qualquer token recusado |
| `app/Modules/Identity/Http/Middleware/EnsureEmailIsVerified.php` | 403 `email_not_verified` para rotas futuras que exigirem e-mail confirmado |
| `app/Modules/Identity/Http/Middleware/EnsureStringFields.php` | Inclui `token` |
| `lang/pt_BR.json`, `lang/pt_BR/passwords.php` | Textos dos e-mails e mensagens de senha |
| `resources/views/vendor/mail/{html,text}/message.blade.php` | Cabeçalho do e-mail aponta para o frontend |
| `tests/Feature/Identity/EmailVerificationTest.php` | 18 testes |
| `tests/Feature/Identity/PasswordResetTest.php` | 21 testes |
| `tests/Unit/ArchitectureTest.php` | Exceção do preset de segurança para o `sha1` do link de verificação |
| `apps/web/src/app/(auth)/{esqueci-senha,redefinir-senha,verificar-email/[id]/[hash]}/page.tsx` | Páginas novas |
| `apps/web/src/features/identity/components/*` | Formulários de esqueci/redefinir senha, status da verificação, botão de reenvio; aviso no painel; link no login |
| `apps/web/src/features/identity/components/redirect-if-authenticated.tsx`, `app/(auth)/login/page.tsx` | Quem já está logado e chega ao login com `?next=` vai para o `next` |
| Testes do web | `forgot-password-form`, `reset-password-form`, `verify-email-status` |

### Decisões técnicas

- **Links para páginas do frontend**, com a assinatura da verificação **relativa** e o token de redefinição no **fragmento** (ADR 0010 atualizada).
- **Mesma resposta para qualquer e-mail** no "esqueci minha senha", e **uma mensagem** para qualquer token recusado.
- **E-mails pela fila**: o cadastro e o pedido de redefinição não esperam o SMTP, e o tempo de resposta não depende de o e-mail existir.
- **`EnsureEmailIsVerified` próprio** com `code` estável; nenhuma rota usa ainda (não há funcionalidade protegida na Fase 1).
- **Exceção no preset de segurança do Pest** para o `sha1(email)` do link de verificação, formato exigido pelo Fortify; a integridade vem da assinatura HMAC-SHA256.

### Problemas encontrados na validação e corrigidos

- **`Referrer-Policy: no-referrer` quebrava a verificação.** Num GET para a mesma origem o navegador não envia `Origin`; sem `Referer`, o Sanctum não reconhecia a sessão e a API respondia 401. O login então redirecionava ao painel, perdendo o link. Trocado por `same-origin`.
- **O login ignorava `?next=` para quem já estava logado**: agora segue para o `next` (só caminhos internos).
- **Cabeçalho do e-mail apontava para a API** (`APP_URL`): o layout passou a usar `FRONTEND_URL`.
- **Worker da fila precisa ser reiniciado** depois de mudanças no código (`docker compose restart worker`), porque o `queue:work` mantém o código carregado. Isso vale para qualquer alteração em notificações, jobs ou providers.
- Nos testes do web, `beforeEach(() => vi.mocked(fn).mockReset())` devolvia o mock, e o Vitest o chamava como rotina de limpeza. Os testes usam corpo em bloco.

### Validação no navegador (com Mailpit)

| Fluxo | Resultado |
|---|---|
| Cadastro | ✔ painel com aviso "Confirme seu e-mail"; e-mail "Confirme seu e-mail" no Mailpit, em português, com link para `/verificar-email/...` |
| Link de verificação (logado) | ✔ "E-mail confirmado"; painel mostra "Verificado" e o aviso some |
| Link com assinatura adulterada | ✔ "Este link é inválido, expirou ou pertence a outra conta" e botão de reenvio |
| Esqueci minha senha | ✔ mensagem genérica; e-mail "Redefina sua senha" com link `/redefinir-senha#token=...` e cabeçalho apontando para o frontend |
| Redefinir senha | ✔ "Sua senha foi redefinida" |
| Mesmo link de novo | ✔ "Este link de redefinição é inválido ou expirou" com "Pedir um novo link" |
| Login com a nova senha | ✔ painel |

O usuário e os e-mails do teste manual foram removidos.

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make test` | ✔ API 205 testes; web 83 testes |
| `make lint` | ✔ |
| Build de produção | ✔ páginas novas: `/esqueci-senha` e `/verificar-email/[id]/[hash]` (Partial Prerender), `/redefinir-senha` (estática) |

### Pendências e riscos

- **O token de redefinição fica no histórico do navegador** (fragmento da URL) até ser usado ou expirar; ele é de uso único e vale 60 minutos.
- **Sem limite global por conta** no "esqueci minha senha": o limite é por e-mail + IP, e o broker só envia um e-mail por minuto para a mesma conta.
- **E-mails em produção** dependem de um provedor SMTP/transacional configurado (fora do escopo; entra com o deploy).
