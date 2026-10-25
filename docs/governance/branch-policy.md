# Política de branches

## Branches permanentes

| Branch | Origem das mudanças | Proteção |
| --- | --- | --- |
| `main` (padrão) | `homolog` (release), `hotfix/*`, Dependabot de segurança | ruleset `main e homolog` |
| `homolog` | `dev` (promoção), `fix/*` de homologação, propagação de `hotfix/*`, sincronização da `main` | ruleset `main e homolog` |
| `dev` | branches temporárias, propagação de `hotfix/*`, sincronização de `main` e `homolog`, Dependabot de versões | ruleset `dev` |

Nenhuma delas é apagada, recriada ou reescrita.

## Branches temporárias

Formato `tipo/numero-issue-descricao-curta`, minúsculas e hífens:

| Tipo | Uso | Nasce de | Exemplo |
| --- | --- | --- | --- |
| `feat` | funcionalidade | `dev` | `feat/51-demo-upload` |
| `fix` | correção (em `dev`) ou correção de homologação (em `homolog`) | `dev` ou `homolog` | `fix/82-session-error` |
| `docs` | documentação | `dev` | `docs/49-project-status` |
| `refactor` | refatoração | `dev` | `refactor/59-binary-client` |
| `test` | testes | `dev` | `test/65-accessibility` |
| `infra` | CI, Docker, borda, configuração | `dev` | `infra/47-branch-protection` |
| `research` | pesquisa versionada | `dev` | `research/74-training-poc` |
| `hotfix` | falha crítica na versão aprovada | `main` | `hotfix/82-critical-session-error` |

1. O número é o da issue principal; a descrição do PR referencia essa mesma issue.
2. Sem espaços, acentos ou caracteres especiais.
3. Uma branch por entrega; nunca reutilizar uma branch antiga para outro trabalho.
4. Apagar depois do merge, quando o trabalho estiver integrado. O repositório não apaga sozinho
   (`delete_branch_on_merge` desligado), para não arriscar as permanentes.

A verificação `Governance (PR)` (`.github/workflows/governance.yml`) recusa nome fora do padrão e
combinações de origem e destino não previstas:

| Destino | Origens aceitas |
| --- | --- |
| `dev` | `feat`, `fix`, `docs`, `refactor`, `test`, `infra`, `research` (`tipo/<n>-...`), `hotfix/<n>-...`, `main`, `homolog`, `dependabot/*` |
| `homolog` | `dev`, `fix/<n>-...`, `hotfix/<n>-...`, `main` |
| `main` | `homolog`, `hotfix/<n>-...`, `dependabot/*` |

## Proteção (rulesets)

| Regra | `dev` | `homolog` e `main` |
| --- | --- | --- |
| PR obrigatório (0 aprovações: mantenedor único) | sim | sim |
| Checks `API (Laravel)`, `Web (Next.js)`, `E2E (Playwright)`, `Governance (PR)` | sim | sim |
| Branch atualizada com o destino antes do merge (`strict`) | sim | não (ver abaixo) |
| Só merge commit | sim | sim |
| Bloqueio de force push e de exclusão | sim | sim |
| Bypass | nenhum | nenhum |
| Histórico linear | não (conflita com merge commits) | não |

Por que `homolog` e `main` não usam `strict`: cada promoção cria no destino um merge commit que a
origem não tem; com `strict`, toda promoção exigiria antes um PR de volta. A CI do PR já roda
sobre o resultado da junção (`refs/pull/n/merge`), e conflitos sempre bloqueiam o merge.

Quando houver equipe, a política passa a exigir revisão de outra pessoa (aprovações ≥ 1 e
CODEOWNERS), por PR que altere esta tabela.

## Estratégia de merge

Merge commit em todos os PRs. Sem squash e sem rebase de histórico público: os commits da branch
ficam na história, com a autoria preservada.

## Sincronização e propagação

| Situação | PR |
| --- | --- |
| Correção de homologação entrou na `homolog` | `homolog → dev` |
| Hotfix entrou na `main` | `hotfix/* → homolog` e `hotfix/* → dev` (ou `main → homolog` e `main → dev`) |
| Release mesclada na `main` | `main → homolog` e `main → dev` quando houver conteúdo que elas não têm |
| Dependabot de segurança mesclado na `main` | igual a hotfix |

A propagação acontece logo depois do merge que a exige, para nenhuma mudança futura da `dev`
sobrescrever uma correção já aprovada. Conflitos de propagação são resolvidos numa branch de
sincronização, nunca diretamente na permanente.
