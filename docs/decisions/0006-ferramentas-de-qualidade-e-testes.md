# 0006 — Ferramentas de qualidade e testes

- Status: Aceita
- Data: 2026-10-16

## Decisão

| Área | Ferramenta |
|---|---|
| Testes backend | Pest, com banco PostgreSQL de testes (`cs_tactical_test`) |
| Formatação PHP | Laravel Pint (preset `laravel`) |
| Análise estática PHP | Larastan, começando no nível 6 |
| Testes frontend | Vitest + Testing Library |
| Lint/format frontend | ESLint (flat config) + Prettier com `prettier-plugin-tailwindcss` |
| Tipos | TypeScript `strict` + `noUncheckedIndexedAccess` |
| Commits | Conventional Commits |
| CI | GitHub Actions (lint + testes, sem deploy) |

Sem git hooks com Node no host na Fase 0: eles exigiriam Node instalado no WSL. Lint e testes rodam via `make` e no CI.

## Alternativas consideradas

- **PHPUnit puro**: o Pest roda sobre o PHPUnit, com sintaxe mais concisa e testes de arquitetura (`arch()`).
- **SQLite nos testes**: mais rápido, mas sem paridade com JSONB, constraints e UUID do PostgreSQL.

## Consequências

- Qualquer pessoa roda a mesma verificação localmente (`make lint`, `make test`) e no CI.
