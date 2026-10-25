# Política de documentação

Documentação e código mudam no mesmo PR. Idioma: português na documentação e na interface; inglês
em código, tabelas e commits.

## Quando atualizar

| Documento | Atualizar quando |
| --- | --- |
| [README.md](../../README.md) | comandos, endereços, stack ou estrutura mudam |
| [STATUS.md](../STATUS.md) | uma fase, etapa ou bloqueio muda de situação (no mesmo PR) |
| ADR (`docs/decisions/`) | decisão arquitetural relevante; ADR aceita não é editada para mudar de ideia: nova ADR a substitui |
| Contratos (`docs/api/`) | rota, campo, código de erro ou limite muda |
| Banco (`docs/database/`) | migration nova |
| Arquitetura (`docs/architecture/`) | módulo novo, regra de dependência, autenticação, autorização, headers |
| Planos (`docs/development/`, `docs/training/`) | ao executar: seção "Execução" com ajustes e resultados |
| Relatórios e auditorias | só no fechamento da fase ou etapa a que pertencem |
| Roadmaps | decisão de escopo ou ordem aprovada |
| Implantação (`docs/deploy/`) | requisito ou bloqueio de produção muda; item só sai da lista com a verificação registrada |
| Governança (`docs/governance/`) | regra de fluxo, branch, label, estado ou check muda |

## Regras

- Relatórios e auditorias históricos não são reescritos para refletir o estado atual: o estado
  atual fica no `STATUS.md` e nos documentos vivos.
- Mudança técnica relevante tem rastreio: issue, PR e, quando couber, ADR.
- Mudança pequena (renomear, corrigir texto) não pede documento novo.
- Classificar o que se afirma: confirmado, documentado, potencial; estimativa nunca aparece como
  medida.
- Sem segredos, dados pessoais ou atribuição automática a ferramentas em qualquer documento.
