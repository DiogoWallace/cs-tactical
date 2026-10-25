# Política de homologação e release

## Promoção para homologação

PR `dev → homolog`, título `chore(release): promote dev to homolog — RC n`. A descrição traz:

```md
## Escopo da homologação
Issues incluídas: #n, #m
PRs incorporados: #a, #b

## Validação
- [ ] CI verde
- [ ] Testes locais relevantes (quais)

## Plano de homologação
Cenários a verificar, ambiente, dados de teste.

## Riscos conhecidos
```

Condições: conjunto selecionado, CI verde, nenhum bloqueio técnico, plano documentado.

### Corte parcial

Se a `dev` tiver trabalho que não deve ir para a homologação, a promoção sai de uma branch
`release/rc-n` criada a partir de um commit da `dev` que contém só o conjunto aprovado, com PR
`release/rc-n → homolog` aberto pelo responsável. Sem reverter nem reescrever a `dev`. Até a
verificação de governança aceitar `release/*`, isso exige um PR que altere a política e o
workflow.

## Critérios de aprovação

- Fluxos e regras de negócio das issues verificados, com regressão dos módulos afetados.
- Autenticação, autorização, responsividade, falhas, desempenho e acessibilidade quando relevantes.
- Nenhuma falha bloqueante aberta.
- Resultado registrado (comentário "Resultado da homologação") com evidências. Testes
  automatizados são evidência; a aprovação é uma declaração explícita do responsável.

## Release para a `main`

PR `homolog → main`, título `chore(release): promote homolog to main — vX.Y.Z`:

```md
## Versão
vX.Y.Z

## Escopo
Closes #n
Closes #m

## Validação
Resultado da homologação e evidências (links).

## Riscos conhecidos

## Rollback
Como voltar (revert do merge commit, versão anterior da imagem, migrations envolvidas).
```

Depois do merge:

1. Tag anotada `vX.Y.Z` no merge commit da `main` e GitHub Release com as notas (issues e PRs).
2. Sincronização `main → homolog` e `main → dev` quando houver diferença.
3. Issues fechadas pelo PR, estado **Done**.

## Versionamento

SemVer a partir de `v0.1.0-foundation` (tag existente). Enquanto o produto não tiver usuários
externos, a série é `0.y.z`: `y` para releases com funcionalidade, `z` para correções. Ciclo
apenas de governança ou documentação não gera tag de produto.

## Release aprovada × versão implantada

Estar na `main` (e ter tag) significa **aprovado para produção**. Implantado é outra informação,
registrada por GitHub Deployments no ambiente quando houver deploy. Hoje não há ambiente de
produção ([bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md)).

## Correções e rollback

- Defeito antes da release: correção de homologação ([github-workflow.md](github-workflow.md#homologação)).
- Defeito depois da release: hotfix ([github-workflow.md](github-workflow.md#hotfix)).
- Rollback de código: PR que reverte o merge commit (`git revert -m 1 <merge>`), com issue; nunca
  reset ou force push. Migration já aplicada exige plano próprio de reversão.

## Dependabot

| Tipo | Destino | Processo |
| --- | --- | --- |
| Atualização de versão (semanal) | `dev` (`target-branch: dev` em `.github/dependabot.yml`) | PR comum: CI, revisão, segue o ciclo até a `main` |
| Atualização de segurança | `main` (o GitHub sempre usa a branch padrão) | hotfix: CI, revisão, merge e propagação para `homolog` e `dev` |
| Major | `dev` | revisão do guia de migração; se exigir código, PR próprio com issue |

Sem automerge. Alertas são triados como em [dependencias.md](../development/dependencias.md);
nenhum é ignorado sem risco aceito registrado. PRs do Dependabot abertos contra a `main` antes desta
política (#26, #27, #28) não tiveram o destino alterado; quando a `dev` existir, o Dependabot abre
os próximos contra ela, e os antigos são fechados ou recriados por decisão do responsável.
