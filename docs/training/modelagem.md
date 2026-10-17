# Modelagem de dados — Training (proposta)

> Planejamento (T0, 2026-10-17). **Nenhuma migration nesta etapa.** Segue os princípios de
> [modelo-conceitual.md](../database/modelo-conceitual.md) e o estilo de
> [academy-learning.md](../database/academy-learning.md): UUIDv7, `timestamptz`, enums como `string`
> + check, FKs com `ON DELETE` explícito, unicidade no banco.

## Visão geral

```
users 1─0..1 user_steam_accounts                         (Identity)
training_servers 1─N training_server_credentials
training_maps 1─N training_map_versions
training_exercises 1─N training_exercise_versions N─1 training_map_versions
training_metric_definitions (key@versão)
users 1─N training_sessions N─1 training_exercise_versions ; training_sessions N─1 training_servers
training_sessions 1─N training_session_participants N─1 users
training_sessions 1─N training_event_batches 1─N training_events
training_sessions 1─N training_attempts
training_sessions 1─N training_results 1─N training_metric_values N─1 training_metric_definitions
```

## Tabelas

### `user_steam_accounts` (módulo `Identity`)

| Campo | Regras |
|---|---|
| `id` | UUIDv7 |
| `user_id` | FK `users` **ON DELETE CASCADE**; único |
| `steam_id64` | `bigint`; único; check de faixa de conta individual (`76561197960265728` + n) |
| `linked_at`, `updated_at` | `timestamptz` |

- Responsabilidade: vínculo verificado por OpenID. Dado pessoal (identificador público na Steam).
- Histórico de vínculos (para a carência de troca e auditoria) em log estruturado, não em tabela, no
  MVP. Nome e avatar da Steam **não** são guardados (buscados na hora, se preciso, respeitando os
  termos da Web API).

### `training_servers`

| Campo | Regras |
|---|---|
| `id` | UUIDv7 |
| `name` | único; ex.: `sp-01-a` |
| `region` | ex.: `br-sp` |
| `public_host`, `port` | endereço de conexão; único `(public_host, port)` |
| `status` | check `provisioning`, `active`, `draining`, `unhealthy`, `disabled` |
| `trust_level` | check `lab`, `production` |
| `capacity` | slots de jogador (1 no MVP) |
| `cs2_build`, `plugin_version`, `engine_version`, `steam_auth` | última leitura do heartbeat |
| `last_heartbeat_at` | índice parcial para alocação: `WHERE status = 'active'` |

- GSLT **não** fica no banco (fica no servidor de jogo). Exclusão: nunca apagar servidor com
  sessões (RESTRICT); desativar.

### `training_server_credentials`

| Campo | Regras |
|---|---|
| `id` | UUIDv7 |
| `server_id` | FK RESTRICT |
| `key_id` | único, público |
| `secret` | cifrado com a `APP_KEY` (cast `encrypted`), nunca exposto depois de criado |
| `created_at`, `expires_at`, `revoked_at`, `last_used_at` | — |

Check: no máximo duas credenciais ativas por servidor (validado na Action, com lock). Dado sensível.

### `training_maps` e `training_map_versions`

| Campo | Regras |
|---|---|
| `training_maps.key` | único (ex.: `cst_strafe`) |
| `training_map_versions.version` | semver; único `(map_id, version)` |
| `workshop_id` | nulo (mapa local no laboratório) |
| `content_sha256` | hash do pacote do mapa; confere o que o servidor carregou (heartbeat) |
| `entity_contract` | versão do contrato de entidades ([mapas-e-exercicios.md](mapas-e-exercicios.md#contrato-de-entidades-do-mapa)) |

Versões imutáveis depois de usadas por uma sessão.

### `training_exercises` e `training_exercise_versions`

| Campo | Regras |
|---|---|
| `training_exercises.key`, `kind`, `status` | `kind`: `counter_strafe`, `aim`, `reaction`, `movement`, `scenario`; `status` como na Academy (`draft`, `published`, `archived`) |
| `version` | inteiro; único `(exercise_id, version)` |
| `map_version_id` | FK RESTRICT |
| `engine_min_version` | versão mínima do Training Engine |
| `definition` | JSONB validado por schema (regras, tentativas, parâmetros, eventos esperados, métricas) |
| `comparability_group` | inteiro; muda quando a versão não é comparável com a anterior |
| `skills` | `text[]` de chaves da taxonomia (integração com a Academy) |
| `published_at` | imutável depois de publicada |

Conteúdo importado de arquivos versionados no repositório, como a Academy (ADR 0016), por um
comando `training:import` (etapa T6).

### `training_metric_definitions`

| Campo | Regras |
|---|---|
| `key`, `version` | único `(key, version)`, ex.: `cs.accurate_shot_rate@1` |
| `value_kind` | check `observed` (observada diretamente), `calculated` (calculada), `estimate` (estimativa) |
| `comparable_with_previous` | booleano, padrão **falso**; verdadeiro só com justificativa revisada |
| `unit` | `ratio`, `ticks`, `game_units_per_second`, `count`, `game_units` |
| `direction` | `higher_is_better`, `lower_is_better`, `neutral` |
| `min_sample` | amostra mínima |
| `calculator` | classe/versão do cálculo na API |

### `training_sessions`

| Campo | Regras |
|---|---|
| `id` | UUIDv7 |
| `user_id` | FK `users` ON DELETE CASCADE |
| `exercise_version_id` | FK RESTRICT |
| `server_id` | FK RESTRICT, nulo até a alocação |
| `status` | check `reserved`, `active`, `completed`, `interrupted`, `expired`, `cancelled`, `revoked` |
| `reserved_at`, `expires_at`, `started_at`, `ended_at`, `end_reason` | coerência por check (ex.: `active` ⇒ `started_at` não nulo) |
| `map_version_id` | FK RESTRICT; versão do mapa efetivamente carregada (conferida pelo hash) |
| `cs2_build`, `tickrate` | informados no claim; não nulos a partir de `active` (check) |
| `plugin_version`, `engine_version` | idem |
| `context_changed` | booleano; verdadeiro se algum lote chegou com versões diferentes das gravadas no claim |
| `last_seq`, `last_contiguous_seq`, `event_count` | controle de completude |

As versões ficam em **colunas** (não só num JSONB) para permitir filtrar, agrupar e impor `not null`.
Junto com `exercise_version_id`, cobrem CS2, plugin, Engine, mapa e exercício; a versão das regras de
cálculo fica no resultado. O estado de confiabilidade é do **resultado**, não da sessão: a mesma
sessão pode ser recalculada por outra calculadora.

Índices: único parcial `(user_id) WHERE status IN ('reserved', 'active')` (uma sessão aberta por
usuário); único parcial `(server_id) WHERE status IN ('reserved', 'active')` (uma por instância no
MVP); `(user_id, exercise_version_id, ended_at DESC)` para histórico; `(status, expires_at)` para
expiração.

### `training_session_participants`

| Campo | Regras |
|---|---|
| `session_id` | FK CASCADE |
| `user_id` | FK `users` ON DELETE CASCADE |
| `steam_id64` | cópia do vínculo no momento da reserva (o vínculo pode mudar depois) |
| `role` | `player` (cenários futuros com mais pessoas: `teammate`, `coach_observer`) |
| `joined_at`, `left_at`, `disconnect_count` | — |

Único `(session_id, steam_id64)`. No MVP há exatamente um participante; a tabela existe para
cenários de equipe sem mudar `training_sessions`.

### `training_event_batches`

| Campo | Regras |
|---|---|
| `id` | o `batch_id` do plugin (PK, idempotência) |
| `session_id`, `server_id` | FKs |
| `first_seq`, `last_seq`, `event_count`, `byte_size`, `body_sha256` | — |
| `received_at` | — |

Retenção igual aos eventos.

### `training_events` (brutos)

| Campo | Regras |
|---|---|
| `session_id` | FK |
| `seq` | único `(session_id, seq)` |
| `event_id` | UUID do plugin |
| `type`, `schema_version` | check da lista fechada |
| `tick`, `game_time` | — |
| `attempt` | nulo |
| `data` | JSONB (≤ 2 KB) |
| `received_at` | — |

- **Particionada por mês** (`received_at`), para apagar por partição (sem `DELETE` em massa).
- PK `(session_id, seq, received_at)` (exigência do particionamento).
- Retenção proposta: **90 dias** (D4). Depois disso as métricas não podem mais ser recalculadas
  daquela sessão; tentativas e resultados continuam.

### `training_attempts`

| Campo | Regras |
|---|---|
| `session_id` | FK CASCADE |
| `number` | único `(session_id, number)` |
| `start_tick`, `end_tick` | check `end_tick ≥ start_tick` |
| `outcome` | `success`, `fail`, `invalid`, `interrupted` |
| `invalid_reason` | nulo |
| `facts` | JSONB pequeno e fechado por exercício (ex.: `{ "stop_ticks": 5, "speed_at_shot": 3.1 }`) |

Gerada pela API ao processar os eventos (não confiada ao plugin), para manter o recálculo possível.

### `training_results` e `training_metric_values`

| Campo | Regras |
|---|---|
| `training_results.session_id` | FK CASCADE; único `(session_id, calculator_key, calculator_version)` |
| `calculator_key`, `calculator_version` | regras de cálculo usadas (ex.: `counter-strafe-calculator@1`) |
| `status` | `computed`, `superseded`, `failed` |
| `reliability` | check `pending`, `validated`, `incomplete`, `suspect`, `invalid` ([metricas.md](metricas.md#confiabilidade-dos-resultados)) |
| `reliability_reasons` | JSONB com códigos (`missing_events`, `implausible_value`, `context_changed`, `summary_mismatch`, `server_revoked`, `cheats_enabled`…) |
| `reviewed_by`, `reviewed_at`, `review_note` | preenchidos quando um `suspect` é reclassificado |
| `plugin_summary_diff` | divergências entre o resumo do plugin e o recálculo (auditoria) |
| `training_metric_values.result_id` | FK CASCADE |
| `metric_definition_id` | FK RESTRICT (a definição carrega `key`, versão e `value_kind`) |
| `user_id`, `exercise_id` | desnormalizados para histórico |
| `comparability_key` | texto calculado: exercício + grupo + métrica@versão + calculadora compatível + época do jogo |
| `value` | `numeric(12,4)`; `sample_size` |
| `quality` | `ok`, `degraded`, `insufficient_sample` |

Índice: `(user_id, comparability_key, computed_at)` — a consulta de evolução só lê uma chave por vez,
o que impede comparar versões incompatíveis por engano. Recalcular cria um resultado novo e marca o
anterior como `superseded` (histórico preservado).

## Regras de exclusão e retenção

| Relação/dado | Regra | Motivo |
|---|---|---|
| Dados do jogador → `users` | CASCADE | Excluir a conta apaga sessões, eventos, tentativas e métricas (LGPD) |
| Sessão → exercício, mapa, servidor | RESTRICT | Resultados citam a versão exata |
| Eventos brutos | 90 dias, por partição | Recalcular e auditar sem guardar para sempre |
| Lotes (metadados) | 90 dias | Idempotência só precisa do período de envio |
| Tentativas, resultados, métricas | Enquanto a conta existir | Histórico de evolução |
| Heartbeats | Só no Redis + última leitura | Operacional |
| Demos (se gravadas) | Fora do banco, 14 dias, só para calibração e disputa (D4) | Arquivos grandes |
| Logs técnicos | 14–30 dias | Diagnóstico |

Dados sensíveis: `steam_id64` (pessoal), IP do jogador (**não** enviado à API; o plugin não o
coleta), credenciais de servidor (cifradas), padrões de jogo vinculados a uma pessoa (pessoais).

## Guardar todos os eventos brutos?

Não no sentido de "todos os ticks". Proposta: guardar **todos os eventos que o contrato define**
(incluindo janelas de evidência) por 90 dias, e permanentemente só tentativas e métricas. Assim:

- toda métrica prevista pode ser recalculada no período de retenção;
- uma métrica **nova** que precise de dados que o contrato não coleta só vale para sessões futuras
  (aceitável: é o mesmo efeito de uma nova versão de exercício);
- o volume fica previsível (abaixo).

## Volume

Hipóteses: sessão de 10 min de counter-strafe ≈ 200 tentativas; ≈ 5 eventos por tentativa + janela de
16 amostras no `shot`; ≈ 1.000 eventos/sessão; ≈ 300 bytes/evento no JSONB (sem índices).

| Sessões por mês | Eventos brutos/mês | Tamanho bruto/mês | Com retenção de 90 dias | Tentativas/mês |
|---|---|---|---|---|
| 1.000 | 1 milhão | ~0,3 GB | ~1 GB | 200 mil |
| 10.000 | 10 milhões | ~3 GB | ~9 GB | 2 milhões |
| 100.000 | 100 milhões | ~30 GB | ~90 GB | 20 milhões |

Até 10 mil sessões/mês o PostgreSQL atual comporta sem mudança de arquitetura. Perto de 100 mil:
avaliar armazenamento colunar (ou exportação dos brutos para arquivos em armazenamento de objetos),
agregados diários materializados e um serviço de ingestão separado. Comparação: o fluxo completo por
tick (posição e velocidade a 64/s) daria ~10 MB por sessão, ~30 vezes mais — por isso não é coletado.
