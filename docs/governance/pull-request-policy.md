# Política de pull requests

## Tipos

| Tipo | Origem → destino | Título | Referência |
| --- | --- | --- | --- |
| Implementação | `tipo/n-...` → `dev` | Conventional Commit (`feat(matches): ...`) | `Refs #n` |
| Correção de homologação | `fix/n-...` → `homolog` | `fix(...): ...` | `Refs #n` |
| Promoção | `dev` → `homolog` | `chore(release): promote dev to homolog — RC n` | lista das issues |
| Release | `homolog` → `main` | `chore(release): promote homolog to main — vX.Y.Z` | `Closes #n` das concluídas |
| Hotfix | `hotfix/n-...` → `main` | `fix(...): ...` | `Closes #n` |
| Sincronização | `main`/`homolog` → `homolog`/`dev` | `chore(sync): merge main into dev` | — |

## Criação

- Descrição pelo [template](../../.github/PULL_REQUEST_TEMPLATE.md): issue, contexto,
  alterações, validação, riscos, evidências e dependências.
- A verificação `Governance (PR)` exige, nos PRs de implementação, correção e hotfix: a issue do
  nome da branch citada (`#n`) e a seção `## Validação`. Promoção e release exigem `## Validação`
  e ao menos uma issue listada.
- `Refs #n` não fecha a issue. `Closes #n` só no PR para a `main` e só para issues concluídas e
  homologadas. Para que o GitHub registre a relação formal (painel "Development"), a referência
  fica na descrição; texto solto em comentário não cria vínculo.
- PR em rascunho até estar pronto para revisão.

## Revisão

Com mantenedor único, a revisão é a autorrevisão registrada: o autor relê o diff completo e
confirma no PR o que verificou. Agentes não aprovam o próprio trabalho: o merge para
`homolog` e `main` é decisão do responsável. Com equipe, passa a valer aprovação de outra pessoa.

O revisor confere: escopo da issue respeitado, arquitetura (`docs/architecture/`), testes que
falham sem a mudança, autorização e limites em rota nova, documentação, nenhum segredo ou dado
pessoal, nenhuma atribuição automática a ferramentas.

## Testes

Os quatro checks são obrigatórios e não têm filtro de caminho: rodam também em PR só de
documentação. Teste local antes do PR conforme o tipo de mudança (`make test`, `make lint`,
`make test-concurrency`, `make e2e`). Nunca desligar, pular ou enfraquecer teste para passar.
Falha intermitente: reexecutar e registrar; se repetir, vira issue.

## Merge

- Merge commit (squash e rebase desligados pelo ruleset).
- Só com os quatro checks verdes e sem conflito; em `dev`, a branch precisa estar atualizada.
- Depois do merge: comentário "Implementação integrada" na issue, estado atualizado, branch
  temporária apagada.

## Correções durante a revisão

Novos commits na mesma branch; nada de force push em branch com PR aberto e revisado. Mudança de
escopo atualiza a descrição do PR e a issue.

## Evidências

Links para o run da CI, saídas de sondagens (`scripts/`), relatórios do Playwright, medições.
Número de testes só quando vier de execução real. Screenshots não podem conter dados pessoais.
