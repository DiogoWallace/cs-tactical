# Métricas e análise de desempenho

> Planejamento (T0, 2026-10-17). **Todas as métricas são hipóteses** até a validação no laboratório
> (T1/T2). Nenhuma será exibida ao jogador sem origem demonstrada e limite documentado.

## Tipos de valor

| Tipo (código) | Definição | Como aparece para o jogador |
|---|---|---|
| **Observado diretamente** (`observed`) | Lido do estado do jogo num tick, sem transformação além de contagem ou unidade (velocidade, botão, dano, gatilho) | Valor com unidade do jogo ("3,1 u/s") |
| **Calculado** (`calculated`) | Obtido de valores observados por regra determinística e versionada (taxas, medianas, diferenças de ticks); reproduzível a partir dos eventos | Valor + regra explicada ("5 ticks ≈ 78 ms") |
| **Estimativa** (`estimate`) | Depende de fatores fora do servidor (latência, interpolação, tela, pessoa) e não pode ser reproduzido só com os eventos | Sempre com aviso e comparada só com o próprio histórico em condições parecidas |

O tipo faz parte da definição da métrica (`training_metric_definitions.value_kind`) e aparece na
interface. Uma métrica não muda de tipo sem nova versão da definição.

Limites gerais (valem para todas):

- **Resolução de um tick** (64/s, ~15,6 ms). O CS2 processa entradas com marcação abaixo do tick
  ("subtick"), mas o plugin e o `cs_script` só enxergam o estado ao fim de cada tick
  (`WasInputJustReleased`: "em algum momento durante o tick atual"). Diferenças menores que um tick
  não são mensuráveis.
- Os valores são os que **o servidor** registrou, que é o que vale para o jogo (acerto, dano), mas
  não são medidas de laboratório da pessoa.
- Unidades do jogo (u/s, unidades de mapa) não têm conversão física; não exibir "km/h" nem metros.

## Catálogo por exercício

### Counter-strafe

Mecânica: o jogador se move lateralmente entre marcas e atira num alvo assim que para.

| Métrica | Tipo | Origem | Cálculo | Limites |
|---|---|---|---|---|
| Tiros | Observado diretamente | `shot` (evento de tiro do jogo) | contagem por tentativa | confirmar disparo do evento no CS2 (T1) |
| Velocidade horizontal no tiro | Observado diretamente | velocidade do pawn no tick do tiro | `sqrt(vx² + vy²)` | **hipótese H2**: pode diferir da velocidade usada pelo jogo na imprecisão (subtick); calibrar contra demo |
| Taxa de tiros precisos | Calculado | anterior | tiros com velocidade ≤ limiar ÷ tiros | o limiar **não** é presumido do CS:GO: é parâmetro da versão do exercício, calibrado na T2 por arma |
| Ticks até parar | Calculado | ticks entre soltar a tecla de direção (`buttons`) e velocidade ≤ limiar | mediana por sessão | resolução de 1 tick |
| Tiros antecipados | Calculado | tiros com velocidade > limiar | contagem e taxa | — |
| Consistência | Calculado | ticks até parar | desvio absoluto mediano (MAD) e intervalo interquartil | precisa de ≥ 20 tentativas válidas |

### Mira e precisão (alvos)

Alvos: bots (dano por evento de jogador) ou entidades do mapa com vida/saída de dano. Escolha por
versão de exercício.

| Métrica | Tipo | Origem | Cálculo | Limites |
|---|---|---|---|---|
| Tiros, acertos, abates | Observado diretamente | `shot`, `hit`, `target_cleared` | contagens | escopetas: 1 tiro, vários projéteis; penetração gera vários impactos (API do `cs_script` documenta) |
| Taxa de acerto | Calculado | anteriores | acertos ÷ tiros (projéteis tratados por arma) | depende do tipo de alvo; não comparar entre versões |
| Taxa de tiro na cabeça | Calculado | grupo de acerto do dano | acertos na cabeça ÷ acertos | só com alvos que têm *hitboxes* (bots) |
| Tempo até eliminar | **Estimativa** | `target_spawned` → `target_cleared` (ticks) | mediana | inclui o atraso até o alvo aparecer na tela do jogador (rede + interpolação) |
| Tempo de conclusão da rodada | Calculado | início → último alvo | ticks | mesma ressalva acima |
| Desempenho por rodada | Calculado | por rodada | as métricas acima por rodada | — |

### Reflexo (estímulo e resposta)

| Métrica | Tipo | Origem | Cálculo | Limites |
|---|---|---|---|---|
| Tempo de resposta no servidor | **Estimativa** | `stimulus` (tick em que o servidor acionou) → `response` (primeiro tick com a ação esperada) | ticks × 15,625 ms | inclui latência servidor→cliente, interpolação, quadro na tela e cliente→servidor. **Não é tempo de reação humano**; muda com o ping |
| Média, mediana, variação | Calculado | anterior | mediana, MAD | comparar só com o próprio histórico em faixa de ping parecida |
| Respostas válidas | Calculado | — | respostas dentro da janela | — |
| Antecipações | Calculado | resposta antes de um mínimo configurado | contagem | intervalo aleatório entre estímulos (definido no exercício) reduz antecipação |

O ping do jogador entra no evento `response` se o plugin conseguir lê-lo (hipótese H8). Sem ping, a
métrica fica oculta ou com aviso forte.

### Movimentação (circuito)

| Métrica | Tipo | Origem | Cálculo | Limites |
|---|---|---|---|---|
| Tempo por trecho e total | Calculado | `checkpoint` (gatilhos do mapa, saída `OnStartTouch`) | diferença de ticks | gatilho detectado no tick; erro máximo de ±1 tick por ponto |
| Caminho percorrido | Calculado | `movement_sample` (posição a cada N ticks) | soma das distâncias | subestima curvas entre amostras; unidade do jogo |
| Execuções incorretas | Calculado | ordem dos checkpoints, gatilhos de "fora da pista", `invalidation` (noclip, teleporte) | contagem | — |
| Consistência | Calculado | tempos totais válidos | MAD | ≥ 5 voltas |

### Posicionamento e decisão (cenários)

| Métrica | Tipo | Origem | Cálculo | Limites |
|---|---|---|---|---|
| Etapas cumpridas | Calculado | zonas/objetivos do roteiro | etapas ÷ etapas previstas | — |
| Tempo até o objetivo | Calculado | ticks | por etapa | — |
| Escolhas registradas | Observado diretamente | zona alcançada ou botão de HUD (`OnCustomHudClicked`) | sequência | — |
| Aderência ao roteiro | Calculado | escolhas × respostas esperadas definidas pelo autor | pontuação da rubrica | mede o **roteiro do autor**, não "inteligência tática"; exibir como "seguiu a recomendação da aula" |

## Indicadores por sessão

Duração ativa, tentativas (válidas, inválidas, interrompidas), fração de eventos recebida, qualidade
do servidor (ticks atrasados), e as métricas do exercício com tamanho de amostra.

## Confiabilidade dos resultados

Cada resultado de sessão tem **um** estado de confiabilidade, calculado pela API (nunca informado
pelo plugin) e guardado com os motivos:

| Estado (código) | Quando | Uso |
|---|---|---|
| Pendente (`pending`) | Sessão em andamento ou aguardando lotes/cálculo | Nada exibido como resultado |
| **Validado** (`validated`) | Todos os eventos recebidos (sem lacunas), versões homologadas e iguais do começo ao fim, servidor `production` com autenticação Steam, nenhuma invalidação, nenhuma inconsistência, amostra mínima atingida | Entra na evolução, nas tendências, nos recordes e nas recomendações |
| **Incompleto** (`incomplete`) | Faltam eventos depois do prazo, a sessão foi interrompida ou a amostra de alguma métrica é insuficiente; o que chegou é coerente | Exibido com aviso; métricas com amostra suficiente aparecem na linha do tempo marcadas; fora de recordes e recomendações |
| **Suspeito** (`suspect`) | Dados bem formados, mas com sinais de erro ou falsificação: valores implausíveis, divergência entre o resumo do plugin e o recálculo, troca de versão no meio da sessão, anomalia no servidor (ex.: servidor depois revogado), padrão estatístico fora do esperado | Exibido só ao próprio jogador, com aviso; fora de toda comparação até revisão (pode virar validado ou inválido) |
| **Inválido** (`invalid`) | Regras do exercício violadas (`sv_cheats`, `host_timescale ≠ 1`, noclip, teleporte por comando), servidor `lab` ou sem autenticação Steam, versão não homologada, participante divergente | Guardado para auditoria; nunca exibido como desempenho |

Regras de transição: `pending` → um dos quatro finais; `suspect` → `validated` ou `invalid` só por
revisão registrada (quem, quando, motivo); recálculo com nova calculadora pode mudar o estado e
gera um resultado novo (o anterior fica `superseded`). Tentativas individuais têm o próprio
desfecho (`success`, `fail`, `invalid`, `interrupted`) e não mudam sozinhas o estado da sessão,
salvo pelos limites da definição do exercício (ex.: mais de 20% de tentativas inválidas →
`invalid`). Servidor sobrecarregado (`server_perf` acima do limite) torna as métricas de tempo
`incomplete` (qualidade degradada), não as contagens.

## Versões registradas

Toda sessão registra, no claim, e todo resultado registra, no cálculo:

| Versão | Onde | Exemplo |
|---|---|---|
| Build do CS2 e tickrate | sessão | `1.41.8.4`, 64 |
| Plugin e Training Engine | sessão | `0.3.1`, `1.0.0` |
| Mapa (versão e hash) | sessão | `cst_strafe@1.2.0` |
| Exercício (versão e grupo de comparabilidade) | sessão | `counter-strafe@3`, grupo 2 |
| Regras de cálculo (calculadora) | resultado | `counter-strafe-calculator@1` |
| Definição de cada métrica | valor da métrica | `cs.accurate_shot_rate@1` |

## Comparabilidade

Dois valores só são comparados **automaticamente** (linha do tempo, tendência, recorde,
recomendação) quando têm a mesma **chave de comparabilidade**:

```
chave = exercício + grupo de comparabilidade do exercício + definição da métrica (key@versão)
        + versão compatível da calculadora + época do jogo
```

- O padrão é **não comparar**: uma versão nova de exercício, métrica ou calculadora só é comparável
  com a anterior se declarar isso explicitamente (`comparable_with_previous: true`), com
  justificativa revisada. Na dúvida, nova chave.
- A "época do jogo" muda quando um patch do CS2 altera movimentação ou armas; a decisão é
  registrada no changelog do Training. Mudança de plugin/mapa que afete a medida também abre nova
  chave.
- Valores de chaves diferentes aparecem em séries separadas, com a marca "a forma de medir mudou
  aqui"; a interface nunca liga os pontos entre elas nem calcula tendência atravessando a mudança.
- Comparação manual entre chaves (ex.: em análise interna) é permitida, sempre rotulada.

## Evolução

| Indicador | Cálculo |
|---|---|
| Linha do tempo por métrica | um ponto por sessão validada (mediana da sessão), por chave de comparabilidade; incompletas aparecem marcadas |
| Tendência | diferença entre a mediana das últimas 5 sessões e das 5 anteriores, com o tamanho da amostra; sem regressão sofisticada no MVP |
| Recorde pessoal | melhor sessão validada por chave de comparabilidade |
| Consistência ao longo do tempo | MAD entre sessões |
| Desempenho por tipo de exercício | as métricas normalizadas pelo próprio histórico (percentil pessoal), nunca contra outros jogadores no MVP |

Linguagem na interface: "nas suas últimas 5 sessões, a mediana de ticks até parar caiu de 7 para 5",
nunca "seu reflexo é 180 ms".

## Recomendações

Regras explícitas, versionadas e testáveis (sem aprendizado de máquina no MVP):

| Regra (exemplo) | Recomenda |
|---|---|
| Taxa de tiros precisos < meta da versão em 3 sessões validadas seguidas | Aula com habilidade `movement.counter-strafe` + variante mais lenta |
| Ticks até parar melhorou 2 sessões seguidas e taxa ≥ meta | Variante mais difícil |
| Tempo até eliminar estável e taxa de acerto caindo | Exercício de precisão com menos alvos |
| Nenhuma sessão em 14 dias | Lembrete na tela do painel (sem e-mail no MVP) |

Cada recomendação mostra o motivo ("porque nas últimas 3 sessões…"). Detalhes da ligação com a
Academy em [arquitetura.md](arquitetura.md#integração-com-a-academy).
