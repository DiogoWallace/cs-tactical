# Relatório da Fase 0 — Fundação técnica

Registro de cada etapa: arquivos, decisões, comandos, testes e pendências. Atualizado ao fim de cada etapa.

---

## Etapa 0.1 — Esqueleto do repositório e documentação

**Commit:** `chore: add repository skeleton, architecture docs and ADRs`

### Arquivos criados

| Arquivo | Conteúdo |
|---|---|
| `README.md` | Visão do projeto, início rápido, links da documentação |
| `.editorconfig`, `.gitattributes` | UTF-8, LF forçado em todo texto (evita CRLF vindo do Windows), indentação 2/4 |
| `.gitignore` | `.env*` (exceto exemplos), `compose.override.yaml`, arquivos de editor/SO |
| `.vscode/extensions.json` | Extensões recomendadas (WSL, Docker, PHP, Pint, ESLint, Prettier, Tailwind) |
| `docs/architecture/visao-geral.md` | Componentes, regras do monólito modular, módulos por fase, estrutura do frontend |
| `docs/architecture/autenticacao.md` | Fluxo completo de sessão, CSRF, cookies, Server Components e plano de validação (ajuste 1) |
| `docs/database/modelo-conceitual.md` | Entidades por fase, com papéis de equipe separados da função no jogo (ajuste 4) |
| `docs/api/convencoes.md` | Formato de URLs, respostas, erros e endpoints atuais |
| `docs/decisions/0000` a `0008` | Template e 8 ADRs com as decisões aprovadas |

### Decisões técnicas

- **Documentação em português, código em inglês.** As ADRs também ficaram em português, para facilitar a leitura (a análise inicial previa ADRs em inglês).
- **ADR 0007 — papéis**: três dimensões independentes. `users.platform_role` e `team_members.role` afetam autorização; `team_members.game_role` (IGL, AWPer...) nunca é verificado em Policies.
- **ADR 0008 — conteúdo estruturado (ajuste 3)**: aulas como lista de blocos tipados em JSONB (`markdown`, `video`, `image`, `lineup_ref`, `quiz_ref`...), cada tipo com schema validado no backend e no frontend. Markdown vira só um dos tipos de bloco.
- **Regra de autenticação para Server Components**: eles só leem; escritas acontecem no navegador pelo proxy, porque `Set-Cookie` vindo de chamadas feitas no servidor não chega ao navegador.

### Testes

Não se aplica (só documentação).

---

## Etapa 0.2 — Infraestrutura Docker: PostgreSQL, Redis, Mailpit

**Commit:** `build(infra): add compose services for postgres, redis and mailpit`

### Arquivos criados

| Arquivo | Conteúdo |
|---|---|
| `compose.yaml` | Serviços `postgres` (18), `redis` (8, AOF), `mailpit` (v1.27), rede `internal`, volumes nomeados, healthchecks |
| `compose.override.example.yaml` | Exemplo opcional para expor Postgres (54320) e Redis (63790) no host |
| `.env.example` | `HOST_UID/GID`, portas (`WEB_PORT`, `API_PORT`, `MAILPIT_UI_PORT`), credenciais de dev do Postgres |
| `infra/docker/postgres/init/01-create-test-database.sh` | Cria `cs_tactical_test` na primeira inicialização do volume |
| `scripts/setup.sh` | Cria `.env` sem sobrescrever o existente e grava UID/GID do usuário |
| `scripts/doctor.sh` | Verifica WSL, localização fora de `/mnt`, Docker, Compose, `.env`, UID, `autocrlf` e portas livres |
| `Makefile` | `help`, `setup`, `doctor`, `up`, `down`, `restart`, `ps`, `logs`, `build`, `psql`, `redis-cli`, `destroy` |
| `docs/development/wsl2.md` | Pré-requisitos, localização do código, permissões, volumes, hot reload, portas, problemas comuns (ajuste 2) |

### Decisões técnicas

- **Postgres e Redis não publicados no host** por padrão; só a rede interna. O override de exemplo usa portas fora do padrão para não conflitar com instalações locais.
- **Portas publicadas em `127.0.0.1`**, todas configuráveis no `.env`.
- **Postgres 18 monta o volume em `/var/lib/postgresql`** (mudança da imagem oficial na versão 18; montar em `/data` como nas versões anteriores perderia dados).
- **Nome do projeto Compose fixo** (`name: cs-tactical`), isolando redes e volumes de outros projetos.
- **`HOST_UID`/`HOST_GID` em vez de `UID`/`GID`**: `UID` é variável somente-leitura do bash e causaria conflito.
- **`make up` usa `--wait`**: só retorna quando todos os serviços estão saudáveis.
- **`make destroy`** é o único comando que apaga volumes e exige digitar `apagar`.
- `POSTGRES_PASSWORD` é obrigatória (`:?`): o Compose falha com mensagem clara se faltar.

### Comandos executados

```bash
make setup
make doctor
make up
docker compose exec -T postgres psql -U cs_tactical -tAc "select datname from pg_database ..."
docker compose exec -T redis redis-cli ping
curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8025/
make down && make up        # persistência
```

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make doctor` em ambiente limpo | ✔ todos os itens (aviso esperado de "não é WSL" no ambiente de validação) |
| `make up` | ✔ os 3 serviços `healthy` em ~6 s |
| Bancos criados | ✔ `cs_tactical` e `cs_tactical_test` (PostgreSQL 18.6) |
| `data_directory` do Postgres | ✔ `/var/lib/postgresql/18/docker`, dentro do volume |
| Redis | ✔ `PONG`, `appendonly yes` |
| Mailpit UI | ✔ HTTP 200 em `127.0.0.1:8025` |
| Persistência | ✔ tabela criada, `make down` + `make up`, dado continua lá |
| Detecção de porta ocupada | ✔ com um processo na porta 3000, `make doctor` falha e indica `WEB_PORT` |

### Pendências

- Validar no seu WSL2 real: UID 1000, integração do Docker Desktop e acesso pelo navegador do Windows (o ambiente de validação é Linux sem WSL).
- O repositório ainda não está no GitHub (ver README da entrega).

---

## Etapa 0.3 — Laravel 13 com containers `api` e `worker`

**Commit:** `feat(api): add laravel 13 application with api and worker containers`

### Arquivos criados ou modificados

| Arquivo | Conteúdo |
|---|---|
| `apps/api/` | Laravel 13.36 (skeleton `laravel/laravel` v13.11), PHP 8.4 |
| `apps/api/composer.json` | nome `cs-tactical/api`, `php: ^8.4`, `config.platform.php` fixado em 8.4.26 |
| `apps/api/.env.example` | Postgres, Redis (sessão, cache, fila), SMTP, logs em `stderr`, locale `pt_BR`, `FRONTEND_URL` |
| `apps/api/database/migrations/0001_01_01_000000_create_queue_tables.php` | apenas `job_batches` e `failed_jobs` |
| `infra/docker/api/Dockerfile` | `serversideup/php:8.4-fpm-nginx`, alvo `development` com UID/GID do host |
| `compose.yaml` | serviços `api` (porta `API_PORT` → 8080) e `worker` (`queue:work`), mesma imagem |
| `scripts/setup.sh` | cria `apps/api/.env`, faz build, `composer install` e `key:generate` no container |
| `Makefile` | `sh-api`, `artisan`, `composer`, `migrate`, `fresh` (com confirmação), `tinker` |

### Removido do skeleton (ajuste 5: nada que ainda não será usado)

- `package.json`, `vite.config.js`, `resources/js`, `resources/css`, view `welcome`: a API não serve frontend, então **não há Vite**.
- Migration e model `User`, `UserFactory`: serão recriados na Fase 1 no módulo `Identity`, já com UUIDv7.
- Migrations de `cache`, `sessions` e `jobs`: cache, sessões e filas ficam no Redis.
- Workflows `.github` e arquivos de IA (`AGENTS.md`, `CLAUDE.md`) do skeleton.
- Teste de exemplo que acessava `/` (a rota não existe mais).

### Decisões técnicas

- **Fonte única para conexões**: `DB_*`, `REDIS_*` e `MAIL_*` vêm do `compose.yaml` (a partir do `.env` da raiz) e têm prioridade sobre `apps/api/.env`. A senha do banco existe num só lugar.
- **Sanctum fica para a etapa 0.6**, quando vamos validar o proxy de cookies; tabela de tokens pessoais não será criada (ADR 0003).
- **OPcache desligado em dev** (`PHP_OPCACHE_ENABLE=0`): alterações no código valem na próxima requisição.
- **Nginx escutando só IPv4** (`NGINX_LISTEN_IP_PROTOCOL=ipv4`): sem isso o Nginx falha em hosts sem IPv6.
- **Worker** usa a mesma imagem, com o healthcheck `healthcheck-queue` da própria imagem.
- **Healthcheck da API** aponta para `/up` (nativa do Laravel); `make up` só retorna quando a API está saudável.
- **Extensão `intl` não instalada**: não é necessária agora; entra quando houver formatação por locale.
- `setup.sh` recusa UID 0 (root) e mantém 1000, porque o build não funciona com o usuário root.

### Comandos executados

```bash
composer create-project laravel/laravel apps/api "^13.0"
./scripts/setup.sh           # build, composer install, key:generate
make up
make migrate
docker compose exec api php artisan about
docker compose exec api php artisan test
```

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make setup` completo | ✔ build, `composer install` e `APP_KEY` gerada |
| `make up` | ✔ 5 serviços `healthy` (api, worker, postgres, redis, mailpit) |
| `GET http://localhost:8000/up` | ✔ 200 |
| `make migrate` | ✔ `job_batches` e `failed_jobs` criadas no Postgres |
| `artisan about` | ✔ Laravel 13.36.0, PHP 8.4.26, drivers: pgsql, redis (cache, fila, sessão), smtp, logs stderr |
| Dono dos arquivos criados pelo container | ✔ UID/GID 1000 |
| Worker | ✔ processo `queue:work` rodando |
| Alteração de código sem reiniciar | ✔ rota alterada de `v1` para `v2` refletiu na hora |
| `php artisan test` | ✔ 1 teste passando (Pest entra na 0.4) |

### Observações do ambiente de validação

- O ambiente onde validei não tem IPv6 e bloqueia downloads diretos do GitHub. O `composer install` foi feito pelo host com `--prefer-source`; no seu WSL o `make setup` baixa normalmente dentro do container.

### Pendências

- `config/auth.php` ainda referencia `App\Models\User` (inexistente até a Fase 1); só seria usado por rotas autenticadas, que ainda não existem.

---

## Etapa 0.4 — Health check, Pest, Pint e Larastan

**Commit:** `feat(api): add system health endpoint and quality tooling`

### Arquivos criados ou modificados

| Arquivo | Conteúdo |
|---|---|
| `app/Modules/System/SystemServiceProvider.php` | Primeiro módulo; monta `routes.php` sob `/api/v1` com middleware `api` |
| `app/Modules/System/Actions/CheckSystemHealth.php` | Testa Postgres e Redis, mede latência, registra falhas no log |
| `app/Modules/System/Http/Controllers/HealthController.php` | Controller enxuto: chama a Action e monta a resposta (200/503) |
| `app/Modules/System/routes.php` | `GET /api/v1/health` |
| `config/health.php` | `expose_details` (padrão: só em `local`) |
| `bootstrap/providers.php` | Registro dos providers de módulo |
| `tests/Pest.php`, `tests/Feature/System/HealthCheckTest.php` | 7 testes do health check |
| `tests/Unit/ArchitectureTest.php` | 5 testes de arquitetura |
| `phpunit.xml` | Testes no Postgres `cs_tactical_test`, Redis DB 1, fila síncrona, logs desligados |
| `pint.json`, `phpstan.neon` | Pint preset `laravel`; Larastan nível 6 em `app`, `database`, `routes` |
| `composer.json` | Pest 4, pest-plugin-laravel, Larastan 3; scripts `test`, `lint`, `fmt` |
| `Makefile` | `test`, `test-api`, `lint`, `lint-api`, `fmt`, `fmt-api` |
| `docs/api/convencoes.md` | Documentação do `/api/v1/health` |

### Decisões técnicas

- **`/up` e `/api/v1/health` têm papéis diferentes.** `/up` é *liveness* (o processo responde) e é usado pelo healthcheck do Docker; `/api/v1/health` é *readiness* (as dependências respondem). Se o Docker usasse o segundo, uma queda do Redis reiniciaria a API sem necessidade.
- **Detalhes só em ambiente local.** Em produção a resposta é só `status` e `timestamp`; erros vão para o log.
- **Nenhuma abstração de módulo.** Com um único módulo, o provider registra as rotas diretamente; um padrão compartilhado só vale a pena quando houver um segundo módulo.
- **Variáveis de teste como `<server>` no `phpunit.xml`.** O Laravel lê primeiro `$_SERVER`, onde ficam as variáveis injetadas pelo Compose; sem isso, os testes usariam o banco de desenvolvimento. Há um teste que garante o banco `cs_tactical_test`.
- **`tests/` fora do Larastan**: a sintaxe do Pest não é analisável sem plugin; os testes são verificados pela execução.

### Comandos executados

```bash
make composer c='require --dev pestphp/pest:^4.0 pestphp/pest-plugin-laravel:^4.0 larastan/larastan:^3.0'
make test
make lint
curl localhost:8000/api/v1/health
docker compose stop redis && curl localhost:8000/api/v1/health && docker compose start redis
```

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make test` | ✔ 12 testes, 39 asserções (Pest 4.7) |
| `make lint` | ✔ Pint sem alterações; Larastan nível 6 sem erros |
| `GET /api/v1/health` com tudo no ar | ✔ 200, `database` e `redis` ok |
| Redis parado | ✔ 503, `redis: fail`, banco ok; volta a 200 quando o Redis volta |
| Rota inexistente em `/api/*` | ✔ 404 em JSON |
| Testes usam o banco certo | ✔ `cs_tactical_test`, `APP_ENV=testing` |

### Pendências

- Nenhuma nesta etapa.

---

## Etapa 0.5 — Next.js 16 com Tailwind, shadcn/ui e Vitest

**Commit:** `feat(web): add next.js 16 application with theme, ui kit and tests`

### Arquivos criados ou modificados

| Arquivo | Conteúdo |
|---|---|
| `apps/web/` | Next.js 16.4.2 (App Router, Turbopack, `src/`), React 19.3, TypeScript 5.9 |
| `apps/web/package.json` | scripts `dev`, `build`, `lint`, `typecheck`, `test`, `format`; `engines.node >= 24`; pnpm 10.28 |
| `apps/web/tsconfig.json` | `strict`, `noUncheckedIndexedAccess`, `noFallthroughCasesInSwitch`, alvo ES2022 |
| `apps/web/src/app/globals.css` | Tailwind v4 e tokens de design (dark como padrão, tema claro com os mesmos nomes) |
| `apps/web/src/app/layout.tsx` | `lang="pt-BR"`, fontes Geist locais, tema, cabeçalho |
| `apps/web/src/app/page.tsx` | Página inicial provisória |
| `apps/web/src/app/not-found.tsx`, `error.tsx` | Estados de "não encontrado" e erro padronizados |
| `apps/web/src/app/icon.svg` | Ícone próprio (mira), sem logos de terceiros |
| `apps/web/src/components/ui/` | `button`, `card`, `badge`, `skeleton`, `empty` (shadcn/ui, estilo new-york, Radix) |
| `apps/web/src/components/theme-provider.tsx`, `theme-toggle.tsx`, `site-header.tsx` | Tema escuro padrão com alternância |
| `apps/web/components.json` | Configuração do shadcn/ui para adicionar componentes depois |
| `apps/web/vitest.config.mts`, `src/test/setup.ts` | Vitest 5 + Testing Library + jsdom |
| `apps/web/src/lib/utils.test.ts`, `src/app/__tests__/not-found.test.tsx` | 3 testes |
| `apps/web/.prettierrc.json` | Prettier com ordenação de classes Tailwind |
| `infra/docker/web/Dockerfile` | `node:24-bookworm-slim`, usuário com UID/GID do host, Corepack, healthcheck sem curl |
| `compose.yaml` | serviço `web` (porta `WEB_PORT`), volumes `pnpm-store` e `corepack-cache`, `API_INTERNAL_URL` |
| `scripts/setup.sh` | cria `apps/web/.env` e roda `pnpm install` no container |
| `Makefile` | `sh-web`, `pnpm`, `test-web`, `lint-web`, `fmt-web`; `test`, `lint` e `fmt` cobrem as duas aplicações |

### Decisões técnicas

- **Next.js 16.4 com Cache Components ligado** (padrão do template). Isso afeta a autenticação: leituras de `cookies()` precisam ficar dentro de `<Suspense>`. A documentação de autenticação será atualizada na 0.6, junto com a validação.
- **`AGENTS.md` do Next mantido**: o próprio `next dev` recria o arquivo; ele orienta ferramentas de IA a ler a documentação da versão instalada.
- **Fontes Geist pelo pacote `geist`** (arquivos locais, licença OFL), não pelo Google Fonts: funciona offline e no CI.
- **Paleta própria**: grafite azulado com destaque âmbar. Nenhuma cor, logo ou arte da Valve.
- **Componentes shadcn copiados só quando usados** (ajuste 5). O registro online do shadcn não estava acessível no ambiente de validação; os arquivos vieram do repositório oficial (`shadcn-ui/ui`). No seu WSL, `make pnpm c="dlx shadcn@latest add <componente>"` funciona normalmente.
- **Imagem Debian, não Alpine**: `node_modules` é compartilhado com o WSL pelo bind mount, e binários nativos compilados para glibc funcionam nos dois lados.
- **Store do pnpm em volume nomeado** (`NPM_CONFIG_STORE_DIR`): sem isso, o pnpm criava `.pnpm-store` dentro do projeto.
- **`.next` não fica em volume separado** (a análise previa um volume anônimo): com o UID correto, o bind mount funciona e evita problemas de permissão do volume.
- **`typecheck` roda `next typegen` antes do `tsc`**, porque tipos como `LayoutProps` são gerados pelo Next.
- **Rótulo fixo no botão de tema**: o tema só é conhecido no navegador; um rótulo dependente dele causava erro de hidratação.

### Comandos executados

```bash
npx create-next-app@16.4.2 apps/web --ts --tailwind --eslint --app --src-dir --use-pnpm --turbopack
pnpm add clsx tailwind-merge class-variance-authority radix-ui lucide-react next-themes geist zod server-only
pnpm add -D vitest @vitejs/plugin-react jsdom @testing-library/react @testing-library/dom @testing-library/jest-dom vite-tsconfig-paths prettier prettier-plugin-tailwindcss @types/node@^24
./scripts/setup.sh
make up
make test
make lint
pnpm build
```

### Testes realizados

| Verificação | Resultado |
|---|---|
| `make up` | ✔ 6 serviços `healthy`, incluindo `web` |
| `GET http://localhost:3000/` | ✔ 200, `lang="pt-BR"`, tema `dark` aplicado |
| Rota inexistente | ✔ 404 com a página "Página não encontrada" |
| Hot reload | ✔ texto alterado no host aparece na próxima requisição (`Compiled in 16ms`) |
| Alternância de tema | ✔ dark → light (capturas abaixo) |
| Console do navegador | ✔ sem erros na página inicial após a correção de hidratação |
| `make test` | ✔ API 12 testes; web 3 testes |
| `make lint` | ✔ Pint, Larastan, ESLint, Prettier e TypeScript sem erros |
| `pnpm build` | ✔ build de produção compilado |
| Dono dos arquivos (`node_modules`, `.next`) | ✔ UID/GID 1000 |

Capturas: `docs/fase-0/home-dark.png`, `home-light.png` e `404-mobile.png` na pasta do projeto.

### Pendências

- A interface é provisória; a identidade visual (logo, tipografia de títulos, componentes de navegação) entra quando houver telas reais.
