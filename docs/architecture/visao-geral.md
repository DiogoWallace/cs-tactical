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
6. Toda rota autenticada passa por uma Policy ou Gate. Autorização nunca depende do frontend.

### Módulos e quando nascem

| Módulo | Abrange | Fase |
|---|---|---|
| `System` | health check e infraestrutura | 0 |
| `Identity` | autenticação, usuários, perfis | 1 |
| `Academy` | catálogo: cursos, módulos de curso, aulas, quizzes | 2 |
| `Learning` | progresso individual, matrículas, tentativas | 2 |
| `Teams` | equipes, membros, convites, papéis | 3 |
| `Strategies` | estratégias e playbooks | 3 |
| `Maps` | mapas e lineups de utilitárias | 2 ou 3 |
| `Notifications` | e-mails e notificações | 3 |
| `Training` | sessões e atribuições de treino | 4 |
| `Matches`, `Billing` | análise de partidas, cobrança | pós-MVP |

## Frontend

```
apps/web/src/
├── app/            # rotas (App Router): grupos (marketing), (auth) e (app); proxy.ts fica em src/
├── components/
│   └── ui/         # componentes shadcn/ui, sem regra de negócio
├── features/       # um diretório por área (academy, teams...), criado quando a área existir
├── lib/
│   ├── api/        # apiClient (navegador), serverFetch (servidor), tipos
│   └── env.ts      # variáveis de ambiente validadas com Zod
└── styles/
```

- Server Components por padrão; `'use client'` apenas em componentes interativos.
- Nenhum `fetch` solto em componentes: todo acesso à API passa por `lib/api`.
- Cada rota com dados tem estados de carregamento, erro e vazio.
