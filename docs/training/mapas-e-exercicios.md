# Mapas, exercícios e Training Engine

> Planejamento (T0, 2026-10-17). Capacidades citadas em [viabilidade-cs2.md](viabilidade-cs2.md).

## Princípio: mapa como cenário, plugin como juiz

| Camada | Responsável por | Tecnologia |
|---|---|---|
| **Mapa** | Geometria, pontos de nascimento, alvos, gatilhos, zonas, estímulos visuais, HUD opcional | Hammer (Workshop Tools); `cs_script` opcional |
| **Definição do exercício** | Regras, tentativas, parâmetros, eventos esperados, métricas, critérios de validade | Arquivo versionado no repositório (YAML), importado pela API |
| **Training Engine (plugin)** | Executar a definição: máquina de estados da sessão e das tentativas, observar eventos, dar retorno no jogo, emitir eventos do contrato | C# (CounterStrikeSharp) |
| **API** | Validar, guardar, recalcular métricas oficiais | Laravel |

Por que o juiz fica no plugin e não no `cs_script`:

- só o plugin tem SteamID validado e rede ([viabilidade-cs2.md](viabilidade-cs2.md#2-source-2-workshop-tools-hammer-e-cs_script));
- um só lugar com lógica de exercício, em C# testável fora do jogo;
- a API do `cs_script` mudou em 2025 (remoção de `Instance.OnGameEvent`) e a wiki está desatualizada;
- o mesmo mapa serve a vários exercícios (só muda a definição).

O `cs_script` entra quando a mecânica exigir algo que o plugin não faz bem (ex.: HUD
`custom_hud_layout`, efeitos sincronizados com o mapa). Nesse caso ele só **aciona** o plugin
(ponte a validar na T1, hipótese H7) e nunca é fonte de métrica sozinho.

## Contrato de entidades do mapa

O plugin encontra o que precisa por **nomes e classes combinados**, com versão
(`entity_contract`). Exemplo `v1`:

| Nome (prefixo `cst_`) | Classe | Uso |
|---|---|---|
| `cst_spawn_<n>` | `info_player_*` / `info_target` | posições de início por tentativa |
| `cst_zone_<id>` | `trigger_multiple` | checkpoints, zonas de cenário, área válida (`OnStartTouch`/`OnEndTouch`, via `HookEntityOutput`) |
| `cst_out_<id>` | `trigger_multiple` | fora da área → tentativa inválida |
| `cst_target_<id>` | alvo com vida (entidade danificável) ou ponto de *spawn* de bot | alvos de mira |
| `cst_stimulus_<id>` | entidade que o plugin aciona (`EntFireAtName`/inputs) | estímulos de reflexo |
| `cst_marker_<id>` | `info_target` | marcas de referência (ex.: extremos do counter-strafe) |
| `cst_meta` | entidade com *keyvalues* | `contract_version`, `map_version` |

O plugin confere, ao carregar o mapa, se todas as entidades exigidas pela definição existem; se
faltar alguma, o servidor reporta `map_contract_mismatch` no heartbeat e não recebe reservas daquele
exercício.

## Definição de exercício (exemplo, formato proposto)

```yaml
key: counter-strafe
version: 3
comparable_with_previous: true        # padrão false (novo grupo); true exige justificativa revisada
title: Counter-strafe — AK-47
kind: counter_strafe
skills: [movement.counter-strafe]
map: { key: cst_strafe, version: ">=1.2.0 <2.0.0", entity_contract: 1 }
engine: ">=1.0.0"
session: { max_duration_seconds: 900, min_valid_attempts: 20, idle_timeout_seconds: 120 }
attempt:
  start: { on: zone_exit, zone: cst_zone_strafe_lane }
  end:   { on: shot_or_timeout, timeout_ticks: 192 }
  max_attempts: 300
parameters:
  weapon: weapon_ak47
  accurate_speed_threshold: null      # calibrado na T2; sem valor, a métrica fica desligada
  evidence_window_ticks: 16
events: [attempt_started, shot, attempt_ended, movement_sample, invalidation]
metrics: [cs.shots@1, cs.speed_at_shot@1, cs.accurate_shot_rate@1, cs.stop_ticks@1, cs.consistency_mad@1]
invalidation_rules:
  forbid: [sv_cheats, noclip, timescale, teleport]
  server_slow_tick_ratio_max: 0.02
feedback: { center_text: true }       # retorno imediato no jogo (não oficial)
```

Onde fica o quê:

| Item | Lugar | Por quê |
|---|---|---|
| Tipos de regra (`zone_exit`, `shot_or_timeout`), tipos de evento, coleta | **Código do Engine** (versionado com o plugin) | Comportamento novo exige código e testes |
| Parâmetros, combinação de regras, métricas, limites | **Definição versionada** (repositório → API → plugin no claim) | Ajustar exercício sem novo build do plugin |
| Cálculo oficial das métricas | **API** (calculadora versionada) | Recálculo e correção de fórmula |

Combinação dos dois (recomendada): o Engine oferece um conjunto pequeno e testado de primitivas; a
definição as compõe. Uma definição que pede primitiva desconhecida é recusada pelo plugin
(`engine_too_old`), e a API só aloca servidores com `engine_version` compatível.

## Exercícios candidatos

| Exercício | Mapa | Primitivas do Engine | Métricas confiáveis (ver [metricas.md](metricas.md)) | Pendências |
|---|---|---|---|---|
| Counter-strafe | corredor curto com marcas e alvo fixo | zonas, tiro, janela de velocidade | taxa de tiros precisos (após calibração), ticks até parar, consistência | H2 (velocidade no tick × imprecisão); limiar por arma |
| Mira/alvos | arena com pontos de alvo | alvos (bots ou entidades), dano, rodadas | acertos, taxa de acerto, tiros na cabeça (bots) | escolha bots × entidades; aleatoriedade com semente |
| Reflexo | sala com estímulo luminoso/alvo | estímulo, janela de resposta | contagens e mediana **como estimativa** | H3, H8 (ping) |
| Circuito | pista com checkpoints | zonas ordenadas, amostras de posição | tempos por trecho e total, erros | precisão ±1 tick por gatilho |
| Cenário tático | recorte de mapa competitivo | zonas, etapas, HUD de escolha | etapas e tempos; aderência ao roteiro | autoria pedagógica; mapas oficiais não podem ser redistribuídos modificados sem verificar licença |

Ordem sugerida: counter-strafe (PoC) → circuito (valida gatilhos e mapa próprio) → mira → reflexo
→ cenários.

## Versões e comparabilidade

| Muda | Efeito |
|---|---|
| Parâmetro sem efeito na medida (texto, duração máxima) | Nova versão, `comparable_with_previous: true` |
| Regra, limiar, arma, geometria relevante | Nova versão, **não comparável** (novo grupo) |
| Versão do mapa com mudança de geometria nas zonas | Idem; o mapa sobe *minor/major* conforme o contrato |
| Plugin/Engine com correção na coleta | Registrada no contexto; se afetar a medida, a calculadora marca as sessões anteriores como de outro grupo |
| Patch do CS2 que muda movimento/armas | Marca no histórico; a equipe decide se quebra a comparabilidade (registro em changelog do Training) |

## Produção e distribuição de mapas

- Autoria no Hammer (Windows, GPU com ray tracing). Fonte (`.vmap`) versionada fora deste repositório
  ou com Git LFS (decisão na T8; arquivos grandes).
- Distribuição: Steam Workshop (o cliente baixa da Steam ao conectar). Itens não listados no
  servidor dedicado: **não confirmado** (H4); se não funcionar, mapas públicos na Workshop (D13).
- O servidor confere o hash do mapa carregado contra `training_map_versions.content_sha256`.
- Atualizar um mapa = nova versão + publicação + troca nos servidores em janela de manutenção
  (`draining` → atualização → `active`).
- Sem dependência de um cenário único: exercícios citam `map.key` + faixa de versão; vários
  exercícios por mapa e vários mapas por tipo de exercício.
