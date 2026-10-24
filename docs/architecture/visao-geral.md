# Visão geral da arquitetura

## Componentes

```
Navegador ──► web (Next.js, :3000) ──rewrite /api, /sanctum──► api (Laravel, Nginx+FPM :8080)
                    │                                              │
                    └── Server Components (serverFetch) ───────────┤
                                                                   ├──► postgres (dados)
                                       worker (queue:work) ◄──────►├──► redis (sessões, cache, filas)
                                                                   └──► mailpit (SMTP em dev)
```

- O navegador só conhece a origem do frontend. A API não é chamada diretamente pelo navegador (ver [autenticacao.md](autenticacao.md)).
- `api` e `worker` usam a mesma imagem e o mesmo código; mudam só o processo principal.

## Backend: monólito modular

Cada módulo de negócio vive em `apps/api/app/Modules/<Modulo>`:

```
app/Modules/<Modulo>/
├── Actions/            # casos de uso (uma classe, um método handle())
├── Enums/
├── Events/  Listeners/
├── Http/
│   ├── Controllers/    # enxutos: Request → autorização → Action → Resource
│   ├── Requests/       # validação
│   └── Resources/      # formato das respostas
├── Jobs/
├── Models/
├── Policies/
├── routes.php          # rotas do módulo, montadas sob /api/v1
└── <Modulo>ServiceProvider.php
```

Regras:

1. Um módulo só existe quando tem uma funcionalidade implementada. Pastas vazias não são criadas.
2. Migrations, factories e seeders ficam nas pastas padrão do Laravel (`database/`).
3. Controllers não contêm regra de negócio nem escrevem em models diretamente; chamam Actions.
4. Leitura entre módulos via relacionamentos Eloquent é permitida. Escrita em dados de outro módulo passa pela Action desse módulo ou por evento.
5. Código transversal (formato de erro em `app/Support/Http`, helpers) fica em `app/Support`.
6. Toda rota autenticada passa por uma Policy ou Gate. Autorização nunca depende do frontend. Padrões em [autorizacao.md](autorizacao.md).

### Módulos e quando nascem

| Módulo | Abrange | Fase | Situação |
|---|---|---|---|
| `System` | health check, infraestrutura, preparo da stack E2E | 0 | implementado |
| `Identity` | autenticação, usuários, perfis, papéis de plataforma | 1 | implementado |
| `Academy` | catálogo: cursos, módulos de curso, aulas, quizzes, mídia, importador | 2 | implementado (etapas 2.1–2.3) |
| `Learning` | matrículas, progresso por aula, tentativas de quiz | 2 | implementado (etapas 2.4–2.5); depende da `Academy`, nunca o contrário ([ADR 0015](../decisions/0015-academy-e-learning-publicacao-e-visibilidade.md)) |
| `Teams` | equipes, membros, convites, papéis | 3 | não iniciado |
| `Strategies` | estratégias e playbooks | 3 | não iniciado |
| `Maps` | mapas e lineups de utilitárias | 3 (fora da Fase 2, decisão da etapa 2.0) | não iniciado |
| `Notifications` | e-mails e notificações | 3 | não iniciado |
| `Training` | sessões e atribuições de treino | 4 | não iniciado; a prática em servidores CS2 é a iniciativa "CS Tactical Training", só em planejamento ([ADR 0021](../decisions/0021-arquitetura-do-cs-tactical-training.md), Proposta) |
| `Matches` | análise de partidas a partir de demos enviadas pelo jogador | telemetria (MVP) | planejado ([ADR 0022](../decisions/0022-analise-de-partidas-por-demos.md), Proposta; [plano](../training/mvp-analise-de-demos.md)) |
| `Billing` | cobrança | pós-MVP | não iniciado |

## Frontend

```
apps/web/src/
├── app/            # rotas (App Router): grupos (marketing), (auth) e (app); proxy.ts fica em src/
├── components/
│   └── ui/         # componentes shadcn/ui, sem regra de negócio
├── features/       # um diretório por área, criado quando a área existe: system, identity, academy, learning
├── lib/
│   ├── api/        # apiClient (navegador), serverFetch (servidor), tipos
│   ├── env.ts      # variáveis de ambiente validadas com Zod
│   └── security-headers.ts  # headers e CSP das páginas (ADR 0014)
├── proxy.ts        # proteção das rotas logadas e caminho atual para os Server Components
└── test/           # configuração do Vitest
```

- A `academy` não importa a `learning`: as telas do catálogo recebem os componentes do jogador por
  slots (mesma regra de dependência do backend).

- Server Components por padrão; `'use client'` apenas em componentes interativos.
- Nenhum `fetch` solto em componentes: todo acesso à API passa por `lib/api`.
- Cada rota com dados tem estados de carregamento, erro e vazio.
