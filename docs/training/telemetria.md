# Telemetria: contrato entre plugin e API

> Planejamento (T0, 2026-10-17). Proposta de contrato `training-agent/v1`; nenhum endpoint existe.
> Convenções gerais da API (formato de erro, `code` estável) em [convencoes.md](../api/convencoes.md).

## Transporte: comparação

| Opção | Bidirecional | Tempo real | Complexidade no plugin | Robustez a quedas | Avaliação |
|---|---|---|---|---|---|
| **HTTPS REST, lotes** | Pela resposta (comandos no heartbeat) | Segundos | Baixa (`HttpClient`) | Alta: cada lote é independente e idempotente | **Escolhida** |
| HTTPS, evento a evento | Idem | Imediato | Baixa | Média: muitas requisições, mais limites | Descartada: 64 ticks/s geram tráfego sem ganho |
| WebSocket | Sim | Imediato | Média (reconexão, *heartbeat*, ordem) | Baixa sem buffer próprio | Sem necessidade: não há tela ao vivo no MVP e o jogador recebe o retorno no jogo |
| Só resumo no fim | Não | Não | Mínima | Perde tudo se o fim não chegar | Insuficiente: impede recálculo e auditoria |
| gRPC/HTTP2 streaming | Sim | Imediato | Alta (dependências no .NET do servidor) | Média | Desproporcional ao volume |
| UDP próprio | Não | Imediato | Alta | Baixa | Descartada |
| Logs HTTP do servidor | Não | Segundos | Nenhuma | Baixa (sem retentativa nem versão) | Ver [arquitetura.md](arquitetura.md#alternativas-avaliadas) |

**Decisão proposta**: HTTPS REST com JSON, eventos enviados **durante** a sessão em lotes (a cada
2 s ou 200 eventos, o que vier antes) e um **resumo** no fim. WebSocket só se surgir requisito de
visualização ao vivo (ex.: treinador assistindo), com ADR própria.

## Endpoints (`/api/v1/training/agent`)

| Método | Caminho | Função |
|---|---|---|
| POST | `/heartbeat` | Estado da instância (versões do CS2/plugin/mapa, `steam_auth`, jogadores, desvio de relógio, lotes pendentes). Resposta: comandos (`revoke_session`, `end_session`, `drain`) e configuração |
| POST | `/sessions/claim` | `{ "steam_id": "7656…", "slot": 3 }` → sessão + definição do exercício, ou 404/409 |
| POST | `/sessions/{session}/events` | Lote de eventos |
| POST | `/sessions/{session}/summary` | Resumo calculado pelo plugin (para conferência) + contadores (`event_count`, `last_seq`) |
| POST | `/sessions/{session}/end` | Motivo do fim (`completed`, `time_limit`, `player_quit`, `disconnected`, `server_shutdown`) |

Todas exigem a credencial do servidor; nenhuma aceita cookie. Não entram na lista pública do
`RouteAuthorizationTest`: o teste ganha uma terceira categoria, "rotas de máquina", que exige o
guard `training-agent` e `throttle:`.

## Autenticação máquina a máquina

| Opção | Prós | Contras |
|---|---|---|
| **HMAC-SHA256 por requisição** (chave por servidor) | Integridade do corpo, proteção contra replay com timestamp + nonce, sem PKI, simples no .NET e no PHP | Segredo precisa estar recuperável no servidor (cifrado, não hash) |
| Token Bearer (estilo Sanctum) | Mais simples | Não protege o corpo; token capturado é reutilizável até ser revogado; tokens pessoais estão desligados no projeto (ADR 0003) |
| mTLS | Forte, sem segredo compartilhado | Emissão e rotação de certificados; borda precisa repassar a identidade do cliente |

**Proposta**: HMAC sobre TLS, com mTLS como evolução se o número de servidores crescer.

Cabeçalhos:

```
X-CST-Key-Id:     srv_01J9...        (identifica a credencial, não é segredo)
X-CST-Timestamp:  1792270000         (Unix, segundos)
X-CST-Nonce:      0b9f6c...           (128 bits aleatórios)
X-CST-Signature:  base64(HMAC-SHA256(secret,
                    "v1\n" + METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + NONCE + "\n" + SHA256(body)))
Content-Encoding: gzip               (opcional)
```

Regras: timestamp a até ±120 s do relógio da API; nonce único por credencial guardado no Redis por
10 min (replay → 401 `replay_detected`); comparação em tempo constante; credencial revogada ou
servidor `disabled` → 401. Cada servidor tem credencial própria (no máximo duas ativas, para rotação
sem parada), guardada no servidor de jogo fora do diretório do jogo, legível só pelo usuário do
processo. A credencial só alcança as rotas `agent`.

TLS: obrigatório fora do laboratório (TLS 1.2+, certificado verificado pelo plugin; nada de
desligar a verificação). HMAC não substitui TLS: os eventos têm SteamID, que é dado pessoal.

### O que a assinatura garante e o que não garante

A assinatura HMAC **autentica a origem declarada**: prova que a requisição foi montada por quem
tem o segredo daquela credencial, que o corpo não foi alterado no caminho e que não é uma repetição.
Ela **não** prova que o conteúdo é verdadeiro. Um servidor de jogo comprometido (ou um plugin
alterado nele) tem o segredo e pode assinar telemetria falsificada que passa em todas as verificações
desta seção.

Consequências no desenho:

- A assinatura é condição necessária, nunca suficiente: o resultado passa ainda pelas verificações
  de sessão, participante, schema, plausibilidade e coerência ([metricas.md](metricas.md#confiabilidade-dos-resultados)).
- Inconsistências levam o resultado a suspeito (`suspect`), não a validado (`validated`).
- O dano de um servidor comprometido fica limitado às sessões alocadas nele no período (escopo da
  credencial), que podem ser reclassificadas em bloco depois da revogação.
- Auditorias por amostragem (demo do servidor comparada aos eventos, quando autorizado) e detecção
  de anomalias por servidor são os controles para esse caso ([seguranca.md](seguranca.md)).

## Envelope e eventos

```json
{
  "schema": "training-agent/v1",
  "batch_id": "019a3c2e-7d41-7c1a-9a55-0f2b8e1d4c11",
  "session_id": "019a3c2a-...",
  "sent_at": "2026-11-02T13:05:12.481Z",
  "context": { "plugin": "0.3.1", "engine": "1.0.0", "exercise": "counter-strafe@3", "map": "cst_strafe@1.2.0", "cs2_build": "1.41.8.4", "tickrate": 64 },
  "events": [
    { "seq": 41, "id": "019a3c2e-...", "type": "shot", "v": 1, "tick": 18233, "game_time": 284.89,
      "attempt": 17, "data": { "weapon": "weapon_ak47", "speed_xy": 3.12, "buttons": ["attack"], "ticks_since_dir_release": 5 } }
  ]
}
```

| Campo | Regra |
|---|---|
| `seq` | Inteiro crescente por sessão, sem reutilização; único `(session_id, seq)` |
| `id` | UUIDv7 do evento, gerado no plugin (rastreabilidade) |
| `type` + `v` | Tipo e versão do schema do evento; lista fechada por versão do contrato |
| `tick` / `game_time` | **Relógio de referência** para durações (simulação do servidor, sem depender do relógio de parede) |
| `sent_at` | Relógio de parede UTC do servidor, só para diagnóstico; a API grava também `received_at` |
| `attempt` | Número da tentativa (quando houver) |
| `data` | Campos do tipo, validados por schema (classe PHP por tipo, como os blocos da Academy) |

Tipos iniciais (`v1`): `session_started`, `player_spawned`, `attempt_started`, `attempt_ended`,
`shot`, `hit`, `target_spawned`, `target_cleared`, `stimulus`, `response`, `checkpoint`,
`movement_sample` (amostras limitadas, ver abaixo), `invalidation` (ex.: `noclip`, `sv_cheats`,
`timescale`, `teleport`), `player_disconnected`, `player_reconnected`, `server_perf` (duração dos
ticks, para qualificar a sessão), `session_ended`.

**Evidência limitada em vez de fluxo bruto por tick**: enviar posição e velocidade dos 64 ticks por
segundo daria ~38 mil amostras por sessão de 10 minutos. O plugin envia só **janelas de evidência**
definidas pelo exercício (ex.: velocidade dos 16 ticks antes de cada tiro). Isso basta para
recalcular as métricas previstas e mantém o volume previsível ([modelagem.md](modelagem.md#volume)).

### Versionamento

- `schema` (`training-agent/v1`): estrutura do envelope e dos tipos. Campo opcional novo mantém a
  versão; mudança incompatível cria `v2`, e a API aceita as duas durante a transição.
- Versão por tipo de evento (`v`), versão do exercício, do mapa, do plugin e do CS2 vão em todo
  lote; entram na chave de **comparabilidade** das métricas ([metricas.md](metricas.md#comparabilidade)).

## Validação e rejeição

| Verificação | Resposta |
|---|---|
| Assinatura, credencial, timestamp, nonce | 401 (`invalid_signature`, `replay_detected`, `credential_revoked`) |
| Corpo > 256 KB (descomprimido > 1 MB), > 500 eventos, `data` > 2 KB | 413 `payload_too_large` |
| JSON inválido, tipo desconhecido, campo extra ou fora de faixa | 422 `validation_failed` (lote inteiro rejeitado, com o índice do evento) |
| Sessão de outro servidor, não ativa ou desconhecida | 404 (não revela) |
| SteamID nos eventos diferente do participante | 422 e alerta de segurança |
| Lote depois do fim + 24 h | 409 `session_closed` |
| Excesso de requisições | 429 (limite por credencial, ex.: 120/min; heartbeat em limite separado) |

Valores **bem formados mas implausíveis** (velocidade acima do máximo do jogo, tick no futuro,
tentativa de 1 tick) não são rejeitados: o evento é gravado, a sessão recebe o motivo
`implausible_value` e o resultado fica suspeito (`suspect`). Rejeitar por plausibilidade esconderia defeitos do
plugin e ataques.

**Versões**: o contexto (`cs2_build`, `plugin`, `engine`, `map`, `exercise`) é gravado na sessão no
claim. Um lote com contexto diferente do registrado (troca de versão no meio da sessão) é aceito e
deixa o resultado suspeito (`suspect`) com o motivo `context_changed`.

## Idempotência

- `batch_id` único: reenvio do mesmo lote responde **200** com o mesmo resultado, sem gravar.
- `(session_id, seq)` único: um evento repetido num lote novo é ignorado e contado em
  `duplicates`.
- `summary` e `end` são idempotentes por sessão (o segundo envio igual responde 200; diferente, 409).
- Resposta do lote: `{ "accepted": 198, "duplicates": 2, "last_contiguous_seq": 240, "missing": [] }`,
  para o plugin saber o que reenviar.

## Ordem, atraso e lacunas

- A API aceita lotes fora de ordem; a ordem real é `seq`/`tick`.
- `missing` lista lacunas de `seq`; o plugin reenvia do disco.
- O cálculo só roda quando: `end` recebido **e** (`last_contiguous_seq` = `last_seq` do resumo **ou**
  passou o prazo de 24 h). Com lacunas no prazo, o resultado sai incompleto (`incomplete`), com a fração de
  eventos recebida.

## Retentativas e buffer no plugin

- Fila em memória → arquivo por sessão (JSON Lines, *append-only*, `fsync` por lote) no diretório
  de dados do plugin; o lote só sai do disco depois de 2xx.
- *Backoff* exponencial com *jitter* (1 s, 2 s, 4 s… até 60 s); 4xx (exceto 408/429) não é
  repetido: o lote vai para quarentena local e o heartbeat informa.
- Limite do buffer: 50 MB ou 24 h por servidor; acima disso, descarta os lotes mais antigos já
  marcados como não recuperáveis e registra no log (o resultado sai incompleto, `incomplete`).
- Envio em *thread* separada: nenhuma chamada de rede no *game thread* (evita travar o tick).

## Falhas

| Cenário | Resultado esperado |
|---|---|
| API fora por 5 min | Exercício segue; lotes chegam depois, sem duplicata |
| Plugin reiniciado no meio | Lotes do disco são reenviados; tentativa em curso vira `interrupted` |
| Servidor reiniciado | Idem; sessão `interrupted` se o jogador não voltar na carência |
| Rede degradada | Latência maior, lotes maiores; nada se perde até o limite do buffer |
| Plugin defeituoso enviando lixo | 422 + quarentena; alerta por taxa de rejeição por servidor |
| Credencial vazada | Revogar (401 imediato), emitir nova, investigar sessões do período |
