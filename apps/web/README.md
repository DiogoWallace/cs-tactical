# CS Tactical — Web

Frontend Next.js 16 (App Router, TypeScript strict, Tailwind CSS v4, shadcn/ui). Documentação geral na raiz do repositório:

- [Visão geral da arquitetura](../../docs/architecture/visao-geral.md)
- [Autenticação entre Next.js e Laravel](../../docs/architecture/autenticacao.md)

Todos os comandos rodam no container, via `make` na raiz do repositório (`make pnpm c="..."`, `make test-web`, `make lint-web`).

## Estrutura

```
src/
├── app/            # rotas (App Router)
├── components/
│   └── ui/         # componentes shadcn/ui (sem regra de negócio)
├── features/       # um módulo por área: identity, system, academy (api/, components/, lib/)
├── lib/            # utilitários e acesso à API
└── test/           # configuração dos testes (Vitest)
```

Para adicionar componentes do shadcn/ui: `make pnpm c="dlx shadcn@latest add <componente>"`.
