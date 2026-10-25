# Política de issues

As GitHub Issues são o registro oficial das demandas. Nenhuma implementação começa sem uma issue
com escopo mínimo.

## Templates

| Template | Uso | Título |
| --- | --- | --- |
| [Bug](../../.github/ISSUE_TEMPLATE/bug.yml) | comportamento incorreto | `[BUG] ...` |
| [Feature](../../.github/ISSUE_TEMPLATE/feature.yml) | funcionalidade ou mudança de comportamento | `[FEATURE] ...` |
| [Task](../../.github/ISSUE_TEMPLATE/task.yml) | documentação, testes, infraestrutura, refatoração, segurança | `[DOCS]`, `[TEST]`, `[INFRA]`, `[REFACTOR]`, `[SECURITY]` |
| [Research](../../.github/ISSUE_TEMPLATE/research.yml) | investigação ou decisão | `[RESEARCH] ...` |

Issue em branco está desligada. Vulnerabilidades vão pela aba Security, não por issue pública.
Issues anteriores aos templates continuam como estão.

## Estados

O estado oficial é o campo `Status` do GitHub Project "CS Tactical — Development"
(ver [GitHub Project](#github-project)). Não há labels de estado.

| Estado | Entra quando |
| --- | --- |
| `Backlog` | registrada, ainda sem escopo pronto |
| `Ready` | atende à [definição de Ready](#definição-de-ready) |
| `In Progress` | branch criada e trabalho iniciado (comentário "Desenvolvimento iniciado") |
| `In Review` | PR de implementação aberto para `dev` |
| `In Homologation` | PR mesclado na `dev`, aguardando ou passando pela homologação |
| `Done` | release com a entrega mesclada na `main` (`Closes #n`) |

`blocked` é label de condição, somada ao estado, com a dependência registrada como "blocked by"
e um comentário "Bloqueio identificado". Código na `dev` **não** é Done.

Issue cancelada: fechar como **not planned**, com comentário do motivo.

## Labels

| Grupo | Labels | Regra |
| --- | --- | --- |
| Prioridade | `priority:P0` a `priority:P3` | exatamente uma |
| Área | `area:backend`, `area:frontend`, `area:database`, `area:infra`, `area:security`, `area:quality`, `area:product`, `documentation` (área de docs) | uma ou mais |
| Tipo | `bug` (bug), `enhancement` (feature), `type:tech-debt`, `type:research` | uma |
| Condição | `blocked`, `accessibility` | quando se aplicar |

`documentation`, `bug` e `enhancement` são labels padrão do GitHub já usadas no backlog; não se
criam `area:docs`, `type:bug` nem `type:feature` para não duplicar. `dependencies`, `docker`,
`php`, `javascript` e afins são do Dependabot.

| Prioridade | Significado |
| --- | --- |
| P0 | governança, segurança ou bloqueio: antes de qualquer outra |
| P1 | entrega corrente do produto (hoje, o MVP de análise de partidas) |
| P2 | produção, qualidade e próxima fase |
| P3 | evolução futura, em geral pesquisa |

## Responsabilidade

Quem inicia o trabalho se atribui à issue (`assignee`); uma issue tem um responsável por vez. O
responsável pelo repositório aprova escopo, homologação e release.

## Definição de Ready

- Problema, objetivo e escopo claros, com "fora do escopo".
- Critérios de aceite verificáveis, incluindo os testes esperados.
- Dependências resolvidas ou registradas como "blocked by".
- Decisões de arquitetura e produto necessárias tomadas (ADR ou plano aprovado, quando exigido).
- Autorizações específicas registradas (migration em banco com dados, acesso a arquivos do
  usuário, custos).

## Definição de Done

- Critérios de aceite atendidos, com evidência no PR.
- Checks obrigatórios verdes nos PRs para `dev`, `homolog` e `main`.
- Homologação aprovada pelo responsável.
- Documentação coerente ([documentation-policy.md](documentation-policy.md)).
- Mesclada na `main` pelo PR de release, que fecha a issue.

## Comentários

Comentários registram eventos, não cada commit. Quatro modelos:

```md
### Desenvolvimento iniciado

**Branch:** `feat/51-demo-upload`
**Base:** `dev`

**Escopo:** ...

**Próximo passo:** ...
```

```md
### Bloqueio identificado

**Problema:** ...

**Impacto:** ...

**Dependência:** #número

**Próxima ação:** ...
```

```md
### Implementação integrada

**PR:** #número
**Branch de destino:** `dev`

**Validações:**
- Testes executados.
- Resultado da CI.
- Critérios técnicos atendidos.

**Próxima etapa:** homologação.
```

```md
### Resultado da homologação

**Resultado:** aprovado | reprovado

**Cenários verificados:**
- ...

**Evidências:** ...

**Pendências:** nenhuma | #número

**Próxima etapa:** release | correção
```

Regras: nada de comentário repetido, histórico inventado ou aprovação sem validação; o comentário
não substitui o campo `Status`; mudança de escopo atualiza o corpo da issue e registra o motivo.

## GitHub Project

O projeto "CS Tactical — Development" tem: campo `Status` com os seis estados; sem campo
`Priority` (a label já é a fonte); visões Kanban por Status, Todas as issues, Por prioridade
(agrupada pela label) e Bloqueadas (filtro `label:blocked`). Criá-lo exige o escopo `project` no
token do GitHub; os passos e a situação estão na [issue #77](https://github.com/DiogoWallace/cs-tactical/issues/77).
Enquanto ele não existir, o acompanhamento usa labels de prioridade, `blocked`, milestones e a
situação dos PRs.
