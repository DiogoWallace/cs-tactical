# Governança de desenvolvimento

Como o trabalho entra no CS Tactical: da issue à release. Vale para pessoas e para agentes de
desenvolvimento. Implantada na issue #77; o histórico anterior (PRs direto na `main`) continua
válido como registro e não é reescrito.

| Documento | Conteúdo |
| --- | --- |
| [github-workflow.md](github-workflow.md) | Visão geral do ciclo, homologação, release, hotfix e exceções |
| [branch-policy.md](branch-policy.md) | Branches permanentes e temporárias, nomes, proteção, merge e propagação |
| [issue-policy.md](issue-policy.md) | Templates, estados, labels, prioridade, comentários, Ready e Done |
| [pull-request-policy.md](pull-request-policy.md) | Criação, revisão, testes, referências e merge de PRs |
| [documentation-policy.md](documentation-policy.md) | Quando atualizar README, ADRs, contratos, banco, relatórios e status |
| [release-policy.md](release-policy.md) | Homologação, promoção, versão, tags, rollback e Dependabot |
| [agent-policy.md](agent-policy.md) | Regras obrigatórias para agentes de desenvolvimento |

Guia curto para quem começa: [CONTRIBUTING.md](../../CONTRIBUTING.md). Situação corrente do
projeto: [STATUS.md](../STATUS.md).

## Resumo

```text
Issue → branch tipo/numero-descricao (de dev) → PR para dev → CI → dev
      → PR de promoção dev → homolog → homologação → PR de release homolog → main → tag
```

- `main` é a branch padrão e só recebe releases homologadas e hotfixes.
- Toda mudança chega por PR com merge commit; squash, rebase e push direto ficam bloqueados.
- Checks obrigatórios: `API (Laravel)`, `Web (Next.js)`, `E2E (Playwright)` e `Governance (PR)`.
- Issues fecham pelo PR de release para a `main` (`Closes #n`), nunca antes.

## Rastreabilidade e integração futura

Um sistema de gestão externo poderá ler o estado do desenvolvimento pela API do GitHub. Para isso,
estas convenções precisam ser preservadas (não depender de texto livre em comentários):

| Informação | Fonte estruturada | Convenção que a mantém |
| --- | --- | --- |
| Tarefa | Issue (id e número) | toda branch e PR de implementação aponta para uma issue |
| Projeto | `repository.id` | um repositório por projeto |
| Responsável | `assignees` da issue | atribuir ao iniciar (estado In Progress) |
| Prioridade | label `priority:P0`–`P3` | uma única label de prioridade por issue |
| Status | campo `Status` do GitHub Project | estados de [issue-policy.md](issue-policy.md#estados) |
| Bloqueio | label `blocked` + dependências "blocked by" | relação oficial entre issues |
| Branch | ref `tipo/numero-descricao` | número da issue no nome |
| PR | pull request + `closingIssuesReferences` | `Refs #n` na implementação, `Closes #n` na release |
| Testes | check runs dos jobs obrigatórios | nomes dos jobs estáveis |
| Homologação | PR `dev → homolog` e comentário de resultado | título `chore(release): promote dev to homolog — RC n` |
| Release | tag `vX.Y.Z` e GitHub Release | SemVer, tag no merge commit da `main` |
| Implantação | GitHub Deployments (quando houver) | ambiente registrado à parte da release |
| Histórico | eventos do GitHub (timeline, merges) | nada de histórico reescrito |

Renomear jobs, labels ou estados quebra essa leitura: qualquer mudança passa por PR com a
atualização desta tabela.
