# CS Tactical

Plataforma SaaS para desenvolvimento de jogadores e equipes competitivas de Counter-Strike 2, com dois módulos integrados: **Academy** (aprendizado individual) e **Teams** (gestão de equipes).

## Status

**Fase 2 — Academy**, etapas 2.0 a 2.6 concluídas; em andamento a **2.7** (revisão transversal de segurança, acessibilidade e desempenho), seguida da **2.8** (auditoria e fechamento da fase). Somente ambiente de desenvolvimento: **produção continua bloqueada** ([bloqueios de produção](docs/deploy/bloqueios-de-producao.md), P1–P4 e G1).

| Fase | Situação | O que entrega |
|---|---|---|
| 0 — Fundação | concluída | Monorepo, Docker Compose no WSL2, CI, health check |
| 1 — Identidade | concluída ([relatório](docs/development/fase-1-relatorio.md), [auditoria](docs/development/fase-1-auditoria.md)) | Cadastro, login, sessão, verificação de e-mail, recuperação de senha, conta, papéis de plataforma, painel, E2E, headers de segurança e CSP |
| 2 — Academy | em andamento ([plano](docs/development/fase-2-plano.md)) | Catálogo público de cursos e aulas, importador de conteúdo, matrícula, progresso, quizzes com correção no servidor, painel com "continuar estudando" |
| 3 — Teams | não iniciada | Equipes, membros, convites, estratégias |

Etapas da Fase 2:

| Etapa | Entrega | Situação |
|---|---|---|
| 2.0 | Desenho: ADRs 0015–0019, modelo de dados, regras e contratos | concluída |
| 2.1 | Banco da Academy, `academy:import` e `academy:media-prune` | concluída |
| 2.2 | API pública do catálogo e da mídia | concluída |
| 2.3 | Telas do catálogo e das aulas (Markdown seguro, vídeo, CSP por rota) | concluída |
| 2.4 | Learning: matrícula, conclusão de aula, progresso, "Meu progresso" | concluída |
| 2.5 | Quizzes: tentativas, snapshot, correção, liberação do gabarito | concluída |
| 2.6 | Painel com o cartão da Academy e cabeçalho público conforme a sessão | concluída |
| 2.7 | Revisão transversal de segurança, acessibilidade e desempenho ([plano](docs/development/fase-2-etapa-2.7-plano.md)) | em andamento |
| 2.8 | Auditoria e relatório de fechamento da Fase 2 | não iniciada |

Iniciativas só em planejamento (nada implementado): **CS Tactical Training** ([docs/training](docs/training/visao-geral.md), [ADR 0021](docs/decisions/0021-arquitetura-do-cs-tactical-training.md), status Proposta) e busca com Elasticsearch ([proposta 2.3.1](docs/development/proposta-2.3.1-busca-elasticsearch.md), ADR 0020 reservada).

## Stack

| Camada | Tecnologia |
|---|---|
| Frontend | Next.js 16 (App Router), React 19, TypeScript strict, Tailwind CSS 4, shadcn/ui |
| Backend | Laravel 13, PHP 8.4, Sanctum (sessão por cookie) |
| Dados | PostgreSQL 18, Redis 8 (cache, sessões, filas) |
| Infraestrutura local | Docker Compose no WSL2 |
| Qualidade | Pest, Larastan, Pint, Vitest, Playwright + axe, ESLint, Prettier, GitHub Actions |

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
make artisan c="academy:import"   # importa os cursos de content/academy (opcional)
make test      # roda os testes da API e do frontend
```

Primeira execução: alguns minutos (download de imagens e dependências). As seguintes: segundos.

### Endereços

| Serviço | Endereço |
|---|---|
| Frontend | http://localhost:3000 |
| Status da plataforma | http://localhost:3000/status |
| Cadastro / login | http://localhost:3000/cadastro · http://localhost:3000/login |
| Academy (pública) | http://localhost:3000/academy (cursos em /academy/cursos/{curso}, aulas em /academy/cursos/{curso}/aulas/{aula}) |
| Área logada | http://localhost:3000/painel (Meu progresso: /painel/academy; conta: /painel/conta) |
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
| `make coverage` | Cobertura de testes da API (pcov) e do frontend (v8); ver [docs/development/cobertura.md](docs/development/cobertura.md) |
| `make perf` | Linha de base de desempenho numa stack descartável (cenário pequeno e ampliado, 2.000 aulas); ver [docs/development/desempenho.md](docs/development/desempenho.md) |
| `make test-concurrency` | Testes de concorrência com processos reais (último admin, matrícula e conclusão simultâneas); também rodam na CI. Ver [docs/development/testes-de-concorrencia.md](docs/development/testes-de-concorrencia.md) |
| `make lint`, `make fmt` | Verificação e formatação de código |
| `make artisan c="..."`, `make composer c="..."` | Artisan e Composer no container da API |
| `make pnpm c="..."` | pnpm no container do frontend |
| `make sh-api`, `make sh-web` | Shell nos containers |
| `make psql`, `make redis-cli` | Acesso ao banco e ao Redis |
| `make check-infra` | Valida fila, worker e e-mail |
| `make artisan c="system:check-production-config"` | Confere se a configuração atual é segura para produção (bloqueios P3 e P4) |
| `make artisan c="identity:set-role email@x.com editor"` | Muda o papel de plataforma (`user`, `editor`, `admin`) |
| `make artisan c="academy:import --dry-run"` / `make artisan c="academy:import"` | Valida / importa os cursos de `content/academy`; ver [content/academy/README.md](content/academy/README.md) |
| `make artisan c="academy:media-prune --dry-run"` | Lista (sem `--dry-run`, remove) imagens órfãs da Academy com mais de 24 h; nunca remove imagem referenciada |
| `make destroy` | Apaga containers **e dados** (pede confirmação) |

## Estrutura

```
apps/api            API Laravel, monólito modular (app/Modules/<Modulo>)
apps/web            Frontend Next.js (src/app, src/features, src/lib/api)
infra/docker        Dockerfiles e configuração dos containers
infra/deploy        Modelos para produção (borda, variáveis); nada implantado
content/academy     Cursos da Academy (YAML + imagens), importados por academy:import
docs/               Arquitetura, banco, API, ADRs e guias de desenvolvimento
scripts/            Scripts usados pelo Makefile (setup, doctor, e2e) e verificação da borda
.github/            CI (lint, testes e E2E, sem deploy) e Dependabot
compose.yaml        Serviços: web, api, worker, postgres, redis, mailpit
```

## Documentação

- [Visão geral da arquitetura](docs/architecture/visao-geral.md)
- [Autenticação entre Next.js e Laravel](docs/architecture/autenticacao.md)
- [Autorização (capacidades, Policies, regras para novos módulos)](docs/architecture/autorizacao.md)
- [Segurança HTTP: headers e CSP](docs/architecture/seguranca-http.md)
- [Academy e Learning: regras de negócio, matriz de autorização e limites](docs/architecture/academy.md)
- [Modelo conceitual de dados](docs/database/modelo-conceitual.md) e [modelo detalhado da Academy e do Learning](docs/database/academy-learning.md)
- [Convenções da API](docs/api/convencoes.md) e [contratos da Academy (`/api/v1/academy`, `/api/v1/me`)](docs/api/academy-v1.md)
- [Conteúdo importável da Academy (formato e schemas dos blocos)](docs/academy/conteudo-importavel.md)
- [Decisões arquiteturais (ADRs)](docs/decisions/README.md)
- [Ambiente WSL2 e problemas comuns](docs/development/wsl2.md)
- [Testes E2E (Playwright): executar, depurar e reproduzir](docs/development/e2e.md)
- [Testes de concorrência](docs/development/testes-de-concorrencia.md)
- [Cobertura de testes](docs/development/cobertura.md)
- [Desempenho: linha de base e limites de regressão](docs/development/desempenho.md)
- [Dependências: atualizações e alertas de segurança](docs/development/dependencias.md)
- [Relatório da Fase 0](docs/development/fase-0-relatorio.md)
- [Relatório da Fase 1](docs/development/fase-1-relatorio.md) e [auditoria de fechamento](docs/development/fase-1-auditoria.md)
- [Plano da Fase 2](docs/development/fase-2-plano.md) e [plano da etapa 2.7](docs/development/fase-2-etapa-2.7-plano.md)
- [CS Tactical Training (planejamento)](docs/training/visao-geral.md)
- [Bloqueios de produção](docs/deploy/bloqueios-de-producao.md): requisitos obrigatórios antes do deploy público

## Convenções

- Commits no padrão [Conventional Commits](https://www.conventionalcommits.org/): `feat(api): ...`, `fix(web): ...`, `docs: ...`, `build(infra): ...`.
- Branches curtas a partir de `main` (`feat/...`, `fix/...`, `docs/...`), integradas por PR com **merge commit** (sem squash, preservando os commits da branch), só com a CI (API, Web e E2E) verde.
- Código, nomes de tabelas e commits em inglês; documentação e interface em português.
- Decisões relevantes viram ADR em `docs/decisions/`.
