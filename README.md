# CS Tactical

Plataforma SaaS para desenvolvimento de jogadores e equipes competitivas de Counter-Strike 2, com dois módulos integrados: **Academy** (aprendizado individual) e **Teams** (gestão de equipes).

> Status: **Fase 0 — Fundação técnica** em andamento.

## Stack

| Camada | Tecnologia |
|---|---|
| Frontend | Next.js 16, React 19, TypeScript, Tailwind CSS, shadcn/ui |
| Backend | Laravel 13, PHP 8.4, Sanctum |
| Dados | PostgreSQL 18, Redis 8 |
| Infraestrutura local | Docker Compose, WSL2 |

## Início rápido

Pré-requisitos e detalhes do WSL2 em [docs/development/wsl2.md](docs/development/wsl2.md).

```bash
cd ~/projects
git clone <url-do-repositorio> cs-tactical && cd cs-tactical
make doctor    # verifica Docker, portas e localização do repositório
make setup     # .env, UID/GID, build das imagens, composer install, APP_KEY
make up        # sobe os serviços e aguarda ficarem saudáveis
make migrate   # aplica as migrations (nunca roda automaticamente)
```

| Serviço | Endereço |
|---|---|
| Frontend (Next.js) | http://localhost:3000 |
| API (Laravel) | http://localhost:8000/api/v1/health |
| Mailpit | http://localhost:8025 |

Rode `make help` para ver todos os comandos.

## Estrutura

```
apps/api      API Laravel (monólito modular)
apps/web      Frontend Next.js
infra/docker  Dockerfiles e configurações de containers
docs/         Arquitetura, banco, API, ADRs e guia de desenvolvimento
scripts/      Scripts usados pelo Makefile
```

## Documentação

- [Visão geral da arquitetura](docs/architecture/visao-geral.md)
- [Autenticação entre Next.js e Laravel](docs/architecture/autenticacao.md)
- [Modelo conceitual de dados](docs/database/modelo-conceitual.md)
- [Convenções da API](docs/api/convencoes.md)
- [Decisões arquiteturais (ADRs)](docs/decisions/README.md)
- [Ambiente WSL2](docs/development/wsl2.md)

## Convenções

- Commits no padrão [Conventional Commits](https://www.conventionalcommits.org/) (`feat(api): ...`, `fix(web): ...`, `docs: ...`).
- Código, nomes de tabelas e commits em inglês; documentação e interface em português.
