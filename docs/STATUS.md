# Situação do projeto

Fonte única da situação corrente. Atualizada no mesmo PR que muda uma fase, etapa ou bloqueio
([documentation-policy.md](governance/documentation-policy.md)). Relatórios e auditorias de fase
são históricos e não repetem este estado. Backlog: [issues](https://github.com/DiogoWallace/cs-tactical/issues)
por milestone.

**Conferido em:** 2026-10-25, `main` em `79230ec` (merge do PR #46).

## Fases do produto

| Fase | Situação | Referência |
| --- | --- | --- |
| 0 — Fundação | concluída | [relatório](development/fase-0-relatorio.md) |
| 1 — Identidade | concluída | [relatório](development/fase-1-relatorio.md), [auditoria](development/fase-1-auditoria.md) |
| 2 — Academy | concluída para desenvolvimento; leitor de tela (NVDA) não verificado (#65) | [relatório](development/fase-2-relatorio.md), [auditoria](development/fase-2-auditoria.md) |
| 3 — Teams | não iniciada; falta o plano (#73) | [modelo conceitual](database/modelo-conceitual.md#teams-fase-3) |

## Módulos implementados

| Módulo | O que tem |
| --- | --- |
| `System` | health check, preparo da stack E2E, `system:check-production-config` |
| `Identity` | cadastro, sessão, e-mail, senha, conta, papéis de plataforma, vínculo Steam (M2) |
| `Academy` | catálogo, importador, aulas, quizzes, mídia |
| `Learning` | matrícula, progresso, tentativas de quiz |
| `Matches` | envio de demos em partes (M3, só API) |

## MVP de análise de partidas por demos (épico #50)

| Etapa | Situação |
| --- | --- |
| M0 Plano e ADR 0022 | concluída (ADR ainda Proposta: #55) |
| M1 PoC do analisador | concluída; TypeScript |
| M2 Vínculo Steam | concluída |
| M3 Envio | concluída na `main` (PR #46); pendentes: teste da cota e migration no banco de desenvolvimento (#51), reserva de disco (#54) |
| M4 Análise | não iniciada (#55, #56, #57, #58) |
| M5 Telas | não iniciada (#59, #60, #61) |
| M6 Auditoria | não iniciada (#62) |
| Validação com jogadores | não iniciada (#63, #64) |

## Iniciativas em planejamento

| Iniciativa | Situação |
| --- | --- |
| CS Tactical Training ([ADR 0021](decisions/0021-arquitetura-do-cs-tactical-training.md), Proposta) | T0 e T0.1 concluídas; T3 entregue como M2; T1/T2 bloqueadas por disco e autorização (#74) |
| Busca da Academy (ADR 0020 reservada) | proposta não aprovada (#75) |

## Governança e produção

| Item | Situação |
| --- | --- |
| Fluxo `dev → homolog → main` ([governança](governance/README.md)) | em implantação (#77) |
| G1 — proteção da `main` | em implantação (#47, #77) |
| Alertas de segurança do repositório | desligados (#48) |
| Produção | bloqueada por P1–P4 ([bloqueios](deploy/bloqueios-de-producao.md); #68–#72) |

## Ações que dependem do responsável

- Verificação com NVDA (#65).
- Autorizar a migration `match_uploads` no banco de desenvolvimento (#51).
- Decisões D-M2, D-M4, D-M5 e D-M6 da ADR 0022 (#55).
- Escopo `project` no token do GitHub para criar o GitHub Project (#77).
- Destino dos PRs antigos do Dependabot (#26, #27, #28).

## Próximas entregas

1. Concluir a governança (#77) e ativar os alertas (#48).
2. Fechar a M3 (#51, #54).
3. M4: decisões da ADR (#55), fixture (#56), analisador (#57), processamento (#58).
