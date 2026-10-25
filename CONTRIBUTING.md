# Como contribuir

Resumo do fluxo. Regras completas em [docs/governance](docs/governance/README.md).

## 1. Escolha uma issue

Pegue uma issue aberta em **Ready**, de preferência pela prioridade (`priority:P0` primeiro), sem a
label `blocked`. Atribua-se a ela e comente "Desenvolvimento iniciado"
([modelo](docs/governance/issue-policy.md#comentários)). Sem issue? Abra uma pelos templates.

## 2. Crie a branch

```bash
git fetch origin
git switch -c feat/51-demo-upload origin/dev
```

Formato `tipo/numero-issue-descricao`; tipos: `feat`, `fix`, `docs`, `refactor`, `test`,
`infra`, `research` (e `hotfix`, a partir da `main`, só em falha crítica).
Detalhes em [branch-policy.md](docs/governance/branch-policy.md).

## 3. Trabalhe

- Ambiente: [README](README.md#início-rápido) e [docs/development/wsl2.md](docs/development/wsl2.md).
- Commits em [Conventional Commits](https://www.conventionalcommits.org/) (`feat(matches): ...`),
  com `Refs #51` quando ajudar. Nunca `Closes` em commit.
- Siga a arquitetura ([docs/architecture](docs/architecture/visao-geral.md)) e as ADRs.

## 4. Rode os testes

```bash
make lint
make test
make test-concurrency
make e2e
```

Rode o que a mudança afeta; a CI roda tudo.

## 5. Abra o PR para `dev`

Use o template, com `Refs #51` e a seção `## Validação` preenchida com o que você executou. O PR
precisa dos checks `API (Laravel)`, `Web (Next.js)`, `E2E (Playwright)` e `Governance (PR)`
verdes e é mesclado por merge commit. Veja [pull-request-policy.md](docs/governance/pull-request-policy.md).

## 6. Documente

Mudou rota, tabela, módulo ou situação de uma etapa? Atualize os documentos no mesmo PR
([documentation-policy.md](docs/governance/documentation-policy.md)) e o [STATUS.md](docs/STATUS.md).

## 7. Homologação e release

Depois do merge na `dev`, a issue fica em **In Homologation**. O responsável promove `dev → homolog`,
homologa e abre o PR de release `homolog → main`, que fecha as issues concluídas. Veja
[release-policy.md](docs/governance/release-policy.md).

Agentes de desenvolvimento seguem também [agent-policy.md](docs/governance/agent-policy.md).
