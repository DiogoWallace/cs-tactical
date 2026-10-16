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
