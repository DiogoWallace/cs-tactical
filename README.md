# CS Tactical

Plataforma SaaS para desenvolvimento de jogadores e equipes competitivas de Counter-Strike 2, com dois módulos integrados: **Academy** (aprendizado individual) e **Teams** (gestão de equipes).

> Status: **Fase 1 — Identidade** em andamento. Cadastro, login, logout, área logada, verificação de e-mail, recuperação de senha e edição da conta (perfil, e-mail e senha) funcionando; autorização por papel e dashboard nas próximas etapas.

## Stack

| Camada | Tecnologia |
|---|---|
| Frontend | Next.js 16 (App Router), React 19, TypeScript strict, Tailwind CSS 4, shadcn/ui |
| Backend | Laravel 13, PHP 8.4, Sanctum (sessão por cookie) |
| Dados | PostgreSQL 18, Redis 8 (cache, sessões, filas) |
| Infraestrutura local | Docker Compose no WSL2 |
| Qualidade | Pest, Larastan, Pint, Vitest, ESLint, Prettier, GitHub Actions |

## Pré-requisitos

- Windows com **WSL2 + Ubuntu**, **Docker Desktop** com integração WSL ativada, **Git** e **Make** no Ubuntu.
- Nada de PHP, Composer, Node.js ou PostgreSQL instalado localmente: tudo roda em containers.

Passo a passo do ambiente em [docs/development/wsl2.md](docs/development/wsl2.md).

## Início rápido

O repositório precisa ficar no sistema de arquivos do WSL (`~/projects`), nunca em `/mnt/c`.

```bash
cd ~/projects
git clone <url-do-repositorio> cs-tactical && cd cs-tactical

make doctor    # verifica Docker, WSL, portas livres e localização do repositório
make setup     # cria os .env, ajusta UID/GID, faz o build, instala dependências e gera a APP_KEY
make up        # sobe os serviços e aguarda todos ficarem saudáveis
make migrate   # aplica as migrations (nunca rodam automaticamente)
make test      # roda os testes da API e do frontend
```

Primeira execução: alguns minutos (download de imagens e dependências). As seguintes: segundos.

### Endereços

| Serviço | Endereço |
|---|---|
| Frontend | http://localhost:3000 |
| Status da plataforma | http://localhost:3000/status |
| Cadastro / login | http://localhost:3000/cadastro · http://localhost:3000/login |
| Área logada | http://localhost:3000/painel (conta: /painel/conta) |
| Esqueci minha senha | http://localhost:3000/esqueci-senha (e-mails chegam no Mailpit) |
| API (health check) | http://localhost:8000/api/v1/health |
| Mailpit (e-mails de desenvolvimento) | http://localhost:8025 |

PostgreSQL e Redis não são expostos no host. Para acessá-los com uma ferramenta gráfica, veja [docs/development/wsl2.md](docs/development/wsl2.md#portas). As portas podem ser alteradas no `.env` da raiz.

### Verificação do ambiente

Depois do `make up`, estes comandos confirmam que tudo funciona:

```bash
curl http://localhost:8000/api/v1/health    # {"status":"ok", ... database: ok, redis: ok}
curl http://localhost:3000/api/v1/health    # o mesmo, passando pelo proxy do Next.js
make check-infra                            # job na fila → worker → e-mail visível no Mailpit
make test && make lint
```

## Comandos

`make help` lista todos. Os principais:

| Comando | O que faz |
|---|---|
| `make up` / `make down` | Sobe / derruba os serviços (dados são mantidos) |
| `make ps`, `make logs s=api` | Estado e logs dos serviços |
| `make migrate` | Aplica migrations pendentes |
| `make fresh` | Recria o banco de desenvolvimento (pede confirmação) |
| `make test`, `make test-api`, `make test-web` | Testes |
| `make e2e`, `make e2e-report`, `make e2e-down` | Testes E2E (Playwright) numa stack isolada e descartável; ver [docs/development/e2e.md](docs/development/e2e.md) |
| `make lint`, `make fmt` | Verificação e formatação de código |
| `make artisan c="..."`, `make composer c="..."` | Artisan e Composer no container da API |
| `make pnpm c="..."` | pnpm no container do frontend |
| `make sh-api`, `make sh-web` | Shell nos containers |
| `make psql`, `make redis-cli` | Acesso ao banco e ao Redis |
| `make check-infra` | Valida fila, worker e e-mail |
| `make artisan c="identity:set-role email@x.com editor"` | Muda o papel de plataforma (`user`, `editor`, `admin`) |
| `make destroy` | Apaga containers **e dados** (pede confirmação) |

## Estrutura

```
apps/api            API Laravel, monólito modular (app/Modules/<Modulo>)
apps/web            Frontend Next.js (src/app, src/features, src/lib/api)
infra/docker        Dockerfiles e configuração dos containers
docs/               Arquitetura, banco, API, ADRs e guias de desenvolvimento
scripts/            Scripts usados pelo Makefile (setup, doctor)
.github/workflows   CI (lint e testes, sem deploy)
compose.yaml        Serviços: web, api, worker, postgres, redis, mailpit
```

## Documentação

- [Visão geral da arquitetura](docs/architecture/visao-geral.md)
- [Autenticação entre Next.js e Laravel](docs/architecture/autenticacao.md)
- [Autorização (capacidades, Policies, regras para novos módulos)](docs/architecture/autorizacao.md)
- [Modelo conceitual de dados](docs/database/modelo-conceitual.md)
- [Convenções da API](docs/api/convencoes.md)
- [Decisões arquiteturais (ADRs)](docs/decisions/README.md)
- [Ambiente WSL2 e problemas comuns](docs/development/wsl2.md)
- [Testes E2E (Playwright): executar, depurar e reproduzir](docs/development/e2e.md)
- [Relatório da Fase 0](docs/development/fase-0-relatorio.md)
- [Relatório da Fase 1](docs/development/fase-1-relatorio.md) (em andamento)
- [Bloqueios de produção](docs/deploy/bloqueios-de-producao.md): requisitos obrigatórios antes do deploy público

## Convenções

- Commits no padrão [Conventional Commits](https://www.conventionalcommits.org/): `feat(api): ...`, `fix(web): ...`, `docs: ...`, `build(infra): ...`.
- Branches curtas a partir de `main` (`feat/...`, `fix/...`), integradas por PR com squash.
- Código, nomes de tabelas e commits em inglês; documentação e interface em português.
- Decisões relevantes viram ADR em `docs/decisions/`.
