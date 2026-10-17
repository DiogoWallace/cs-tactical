# Arquitetura do CS Tactical Training

> Planejamento (T0, 2026-10-17). Decisões: [ADR 0021](../decisions/0021-arquitetura-do-cs-tactical-training.md)
> (Proposta). Capacidades do CS2 citadas aqui estão classificadas em [viabilidade-cs2.md](viabilidade-cs2.md).

## Componentes

```
Navegador ──► web (Next.js) ──/api──► api (Laravel, módulo Training) ──► PostgreSQL
                                          │      ▲            │
                                          │      │            └──► Redis (fila "training", nonces, locks)
                                          │      │                     │
                                          │      │                     ▼
                                          │      │               worker (cálculo de métricas, expiração)
                                          │      │
                     HTTPS + HMAC (lote)  │      │  ingest.<domínio> → borda → api (sem cookies, sem CSRF)
                                          ▼      │
   Steam ◄── valida ticket ── Servidor CS2 (instância) + Metamod + CounterStrikeSharp + plugin CS Tactical
     ▲                                    ▲
     └── OpenID (vínculo da conta)        └── cliente CS2 do jogador (UDP 27015+)
```

| Componente | Responsabilidade | Não faz |
|---|---|---|
| **web (Next.js)** | Escolha do exercício, reserva, botão "Conectar", acompanhamento do estado, resultados e evolução | Nunca envia SteamID nem resultados; não fala com o servidor de jogo |
| **api (Laravel, módulo `Training`)** | Vínculo Steam (no `Identity`), catálogo de exercícios, reservas, alocação de servidor, autenticação de servidores, ingestão, validação, cálculo e consulta de métricas | Não confia em nenhum valor sem verificar origem (servidor autenticado + sessão + participante) |
| **worker** | Cálculo das métricas (versionado), expiração de reservas e sessões, reconciliação, limpeza por retenção | — |
| **Servidor CS2** | Executa mapa e exercício; autentica jogadores com a Steam (GSLT, modo seguro) | Não acessa banco nem Redis da aplicação |
| **Plugin (CounterStrikeSharp)** | Reconhece o jogador autorizado, pede a sessão à API, executa o Training Engine, observa eventos, dá retorno no jogo, envia fatos em lote, guarda em disco se a API cair | Não decide o resultado oficial (a API recalcula) |
| **Mapa** | Geometria, alvos, gatilhos e estímulos com nomes previstos no contrato de entidades; opcionalmente `cs_script` para mecânica e HUD | Não identifica jogadores nem fala com a rede (o `cs_script` não tem essas funções) |
| **Redis** | Fila `training`, nonces anti-replay, locks de alocação, estado efêmero de servidores | Não é fonte de verdade |
| **PostgreSQL** | Servidores, exercícios versionados, sessões, eventos brutos (retenção curta), tentativas, resultados e métricas | — |

### Por que manter essa divisão (e o que muda em relação à proposta inicial)

A proposta inicial (Next, Laravel, servidor, plugin, Redis, PostgreSQL) é adequada. Ajustes:

1. **Entrada separada para máquinas**: o plugin chama a API por um host próprio na borda
   (`ingest.<domínio>` → API), não pelo proxy do Next. Assim a ingestão não usa cookies, CSRF nem
   Sanctum, tem limites próprios e pode ser bloqueada sem afetar o site. Rotas sob
   `/api/v1/training/agent/*`, protegidas por um guard próprio ([telemetria.md](telemetria.md)).
2. **O plugin observa; a API decide.** O plugin calcula um retorno imediato para o jogador
   (texto no centro da tela), mas o resultado oficial é recalculado na API a partir dos fatos
   enviados, com fórmulas versionadas. Isso permite corrigir uma fórmula e recalcular o histórico.
3. **Sem WebSocket no MVP.** Não há necessidade de tempo real: o jogador está dentro do jogo
   durante o treino e recebe o retorno pelo próprio plugin. A página da sessão consulta o estado a
   cada poucos segundos enquanto a sessão está ativa. Comandos da API para o servidor (revogar,
   encerrar) voltam na resposta do *heartbeat* do plugin, que já é periódico.
4. **RCON não é canal da aplicação.** O protocolo é TCP sem criptografia e daria controle total do
   servidor; fica desligado ou só em `127.0.0.1` para manutenção.

### Alternativas avaliadas

| Alternativa | Vantagem | Por que não (agora) |
|---|---|---|
| Plugin grava direto no PostgreSQL | Menos código | Credencial de banco numa máquina exposta à internet; acopla o servidor ao esquema. **Vetado** pelo requisito |
| Logs do servidor por HTTP (`logaddress_add_http*`) parseados pela API | Sem plugin para transporte | Formato textual não versionado, sem identidade de sessão nem autenticação forte; nome exato do comando não confirmado |
| Só demos (GOTV) enviadas ao fim e parseadas no backend | Dados completos por tick, auditáveis | Arquivos grandes, processamento pesado, atraso; não identifica sessão sozinho. **Útil como auditoria e calibração** (T1/T2), não como canal principal |
| Toda a lógica em `cs_script` | Oficial, distribuído com o mapa | Sem rede e sem SteamID; ainda precisaria do plugin para identidade e transporte |
| Serviço de ingestão separado (Go/.NET) na frente do Laravel | Escala de escrita | Complexidade prematura; o volume estimado cabe no Laravel ([modelagem.md](modelagem.md#volume)). Reavaliar na expansão |
| Fila externa (Kafka/NATS) | Desacoplamento | Mesmo motivo; Redis já está na stack |

## Separação dos dados

| Camada | Onde vive | Exemplo | Retenção |
|---|---|---|---|
| Operacional do servidor | Redis + última leitura em `training_servers` | heartbeat, versão do CS2 e do plugin, instâncias livres | minutos (Redis); última leitura no banco |
| Eventos brutos | `training_events` (particionada por mês) | tiro no tick 18233 a 3,1 u/s | 90 dias (D4) |
| Eventos agregados | `training_attempts` | tentativa 17: parou em 5 ticks, tiro preciso | enquanto a conta existir |
| Indicadores calculados | `training_results` + `training_metric_values` | taxa de tiros precisos 82% (calculadora v1) | enquanto a conta existir; recalculáveis enquanto houver bruto |
| Histórico de sessões | `training_sessions` + participantes | sessão de 12 min, válida, 214 tentativas | enquanto a conta existir |
| Configuração de exercícios | `training_exercise_versions` (imutáveis) | counter-strafe v3, mapa `cst_strafe` v1.2 | permanente (citada por resultados) |
| Logs técnicos | arquivos/sistema de logs do servidor e da API | falha de envio, latência | 14–30 dias, sem dados pessoais além de IDs |

## Identidade e vínculo com o jogador

### Princípios

1. O SteamID **nunca** vem do navegador. Só de duas fontes da Steam: a resposta OpenID verificada
   pelo backend (vínculo) e o `AuthorizedSteamID` validado pela Steam no servidor de jogo (presença).
2. O servidor de jogo é autenticado por credencial própria; um SteamID só é aceito vindo de um
   servidor registrado, ativo e alocado para aquela sessão. Isso autentica a **origem declarada**,
   não a veracidade: um servidor comprometido assina dados falsos válidos. Por isso o resultado tem
   um estado de confiabilidade separado ([metricas.md](metricas.md#confiabilidade-dos-resultados)).
3. Uma sessão pertence a **um** usuário e a **um** SteamID, decididos na reserva.

### Vínculo da conta (módulo `Identity`)

```
/painel/conta ─► GET /api/v1/me/steam/link (sessão + CSRF) ─► redirect para steamcommunity.com/openid/login
Steam ─► redirect para /api/v1/me/steam/callback?openid.* ─► API:
   1. confere openid.mode, op_endpoint = https://steamcommunity.com/openid/login, return_to = URL esperada
   2. confere o state/nonce da própria aplicação guardado na sessão (amarra a resposta ao usuário logado)
   3. check_authentication direto com a Steam (servidor → servidor)
   4. rejeita openid.response_nonce repetido (Redis, TTL)
   5. extrai o SteamID64 de claimed_id (regex estrita) e grava o vínculo
```

- Tabela `user_steam_accounts` no `Identity`: um SteamID por usuário e um usuário por SteamID
  (únicos). Tentar vincular um SteamID já vinculado a outra conta responde 409 sem revelar a conta.
- Desvincular: permitido sem sessão ativa; o histórico fica (o resultado pertence ao usuário, não ao
  SteamID). Revincular **outro** SteamID tem prazo de carência (ex.: 7 dias) para dificultar troca de
  contas; registro em log de auditoria.
- Exige e-mail confirmado? Recomendação: sim para vincular Steam (evita contas descartáveis
  ocupando SteamIDs) — decisão de produto, diferente da Academy (P3).

### Ciclo de vida da sessão

```
reserved ──claim──► active ──end──► completed ─► (cálculo) ─► resultado
   │                  │  └─desconexão > carência──► interrupted
   ├─expira (ex. 10 min sem conectar)──► expired
   ├─usuário cancela──► cancelled
   └─revogação (vínculo, servidor, abuso)──► revoked   (de qualquer estado não final)
```

1. **Reserva** (`POST /api/v1/training/sessions`, sessão do site + CSRF): exige vínculo Steam, uma
   sessão não final por usuário, versão de exercício publicada. Um lock no Redis aloca uma instância
   livre compatível (mapa e versão do plugin). Resposta: endereço `ip:porta` e prazo.
2. **Conexão**: o servidor aceita a conexão; o plugin espera `OnClientAuthorized`. Sem
   autorização da Steam em X segundos, o jogador é desconectado com mensagem.
3. **Claim** (`POST /api/v1/training/agent/sessions/claim`, assinado pelo servidor) com o SteamID
   autorizado. A API exige: reserva `reserved` (ou `active` do mesmo SteamID, para reconexão) **neste
   servidor**, SteamID igual ao vinculado ao dono, dentro do prazo. Devolve a sessão e a definição do
   exercício. Qualquer outro jogador é desconectado (a instância é de um jogador só no MVP, D5).
   No claim a sessão grava as versões em vigor: build do CS2, plugin, Training Engine, mapa e versão
   do exercício (a versão das regras de cálculo é gravada no resultado). Ver
   [modelagem.md](modelagem.md#training_sessions).
4. **Execução**: eventos em lote ([telemetria.md](telemetria.md)).
5. **Fim**: por comando no jogo, limite de tempo ou de tentativas; o plugin envia o resumo e
   `end`. A API fecha a sessão e enfileira o cálculo.

### Casos-limite

| Caso | Tratamento |
|---|---|
| Identificador previsível | IDs de sessão são UUIDv7 (com 74 bits aleatórios), mas **não são segredo**: nada é autorizado só por conhecer o ID. O servidor precisa estar alocado e autenticado; o jogador precisa ser o SteamID vinculado |
| Reconexão | Mesmo SteamID, mesmo servidor, dentro da carência (ex.: 120 s): a sessão continua; a tentativa em curso é marcada `interrupted` e não entra nas métricas |
| Desconexão inesperada | Após a carência, `interrupted`; os dados já recebidos ficam, e o resultado sai incompleto (`incomplete`) |
| Queda do servidor | Heartbeat ausente por mais de 60 s: a API marca a instância como `unhealthy`, as sessões ativas como `interrupted` e não aloca mais nada nela |
| Eventos duplicados | Únicos por `(session_id, seq)` e por `batch_id`; repetição responde 200 sem gravar ([telemetria.md](telemetria.md#idempotência)) |
| Sessão abandonada | Reserva expira sem claim; sessão ativa sem eventos nem heartbeat do jogador por N minutos é encerrada pelo plugin (e, por garantia, pelo worker) |
| Dois jogadores na mesma sessão | Claim só para o SteamID da reserva; segunda conexão é recusada pelo plugin e, se chegar à API, 409 |
| Mesmo jogador em dois servidores | Claim só no servidor alocado; a outra instância recebe 409 e desconecta |
| Revogação | Desvincular Steam, bloquear a conta ou revogar o servidor: sessões não finais viram `revoked`; o plugin recebe o comando no próximo heartbeat e encerra; requisições com credencial revogada respondem 401 |
| Servidor no modo LAN/inseguro | O plugin informa `steam_auth: false` no heartbeat; a API só aceita esse servidor com `trust_level = lab`, e resultados de `lab` nunca entram no histórico oficial |
| Conta Steam compartilhada | Não é detectável com certeza; termos de uso atribuem os resultados ao titular do vínculo |

### Código de confirmação (opcional)

No laboratório, sem autenticação Steam, a sessão pode exigir um código de 6 caracteres mostrado no
site e digitado no chat (`!cst ABC123`), de uso único e válido por 10 minutos. Em produção é
redundante (a Steam já confirmou a conta) e fica desligado.

## Comportamento em falhas

| Falha | Efeito | Comportamento |
|---|---|---|
| API indisponível durante a sessão | Envio falha | O exercício continua; o plugin guarda os lotes em disco e reenvia com *backoff*. Sessão que não conseguir claim não começa |
| API indisponível no claim | Jogador conectado sem sessão | Mensagem "serviço indisponível"; nova tentativa por até 60 s; depois desconecta |
| Plugin não carregou (patch do CS2) | Servidor sobe sem plugin | Sem heartbeat: a API não aloca a instância e o site mostra "treinos indisponíveis" |
| Jogo trava | Instância cai | Supervisor reinicia; sessão `interrupted`; lotes em disco reenviados quando o plugin voltar |
| Rede entre servidor e API | Atraso | Lotes aceitos até 24 h depois do fim da sessão; depois, o resultado fica incompleto (`incomplete`) |
| Worker parado | Resultado atrasado | Sessão `completed` com resultado "em cálculo"; reconciliação reenfileira |
| Relógio do servidor errado | Assinaturas rejeitadas | Janela de ±120 s; heartbeat informa desvio; NTP obrigatório |

## Integração com a Academy

Regra: **nenhuma dependência circular** entre `Academy`, `Learning` e `Training`. Hoje
`Learning → Academy` (ADR 0015).

```
Academy  ◄── Learning
   ▲
   └──── Training (lê o catálogo público da Academy, só por contrato, só quando precisar)
Recommendations (futuro, opcional) ──► Training, Academy, Learning   (nada depende dele)
```

Contrato proposto, em três passos independentes:

1. **T9a — taxonomia de habilidades** compartilhada por chave estável (ex.: `movement.counter-strafe`,
   `aim.crosshair-placement`), definida em arquivo versionado no repositório. Exercícios declaram
   habilidades na definição; aulas da Academy poderão declarar habilidades no YAML (mudança
   compatível do schema, a decidir pela Academy, ADR 0016).
2. **T9b — composição no frontend**: a página da aula consulta
   `GET /api/v1/training/exercises?skill=movement.counter-strafe` e mostra "Praticar no servidor".
   Nenhum módulo do backend passa a depender de outro.
3. **T9c — recomendações**: regras simples (sem aprendizado de máquina) num módulo que **lê** os
   três. Exemplo: taxa de tiros precisos abaixo da meta em 3 sessões validadas seguidas → recomendar a
   aula com a mesma habilidade e a variante mais lenta do exercício. Detalhes em
   [metricas.md](metricas.md#recomendações).

Alternativa considerada: bloco `practice_ref` no conteúdo da aula. Útil para o autor escolher o
exercício exato, mas exige nova versão de bloco na Academy e resolução cruzada; fica para depois de
T9b, com ADR própria.

A Fase 4 (Teams/Coaching) poderá atribuir exercícios a membros da equipe lendo resultados do
`Training` pela mesma regra de posse e atribuição já prevista para a Academy
([modelo-conceitual.md](../database/modelo-conceitual.md)).
