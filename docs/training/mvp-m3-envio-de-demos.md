# M3 — Envio de demos (plano de implementação)

> Planejamento (2026-10-25, branch `docs/m3-upload-plan`); decisões U1–U6 **aprovadas pelo
> responsável em 2026-10-25**. **Implementado em 2026-10-25** (branch `feat/m3-demo-uploads`; ver
> [Execução](#execução-2026-10-25)). Etapa M3 do
> [plano do MVP](mvp-analise-de-demos.md); decisões de base na
> [ADR 0022](../decisions/0022-analise-de-partidas-por-demos.md) (itens 1, 3 e 5) e nas decisões
> D-M2 a D-M4 do plano do MVP. Depende da [M2](mvp-m2-vinculo-steam.md) (concluída: sem vínculo não
> há envio). A implementação só começa com autorização própria.

## Objetivo

O jogador com conta Steam vinculada envia um arquivo `.dem` de até 600 MB, em partes, pela mesma
origem do site. A API monta o arquivo num disco próprio, confere tamanho, integridade e assinatura, e
deixa o envio **concluído**, pronto para a análise (M4). Envios cancelados, recusados ou abandonados
não deixam nada no disco.

Fora da M3: a análise e o serviço isolado (M4), as telas de "Minhas partidas" (M5), S3 (D-M2: só
depois, como o disco `academy`), leitura do cabeçalho da demo além da assinatura (fica com o
analisador da M4, ver U4), qualquer dado de outro jogador.

## Como funciona o envio em partes

```
Navegador (logado, com Steam)        API (Laravel, módulo Matches)                         Disco matches-uploads
   │ POST /matches/uploads ─────────► confere vínculo, cota, espaço; cria o envio ─────────► (nada ainda)
   │   { name, size }                 (status uploading) e agenda a expiração
   │ ◄── { upload: { id, part_size, parts_total, … } }
   │ PUT  /…/{id}/parts/1  (8 MiB) ──► confere ordem, tamanho e SHA-256 da parte ─────────► uploads/{id}.part
   │ PUT  /…/{id}/parts/2  …          (uma por vez, na ordem; a última pode repetir)        (escreve no offset)
   │ POST /…/{id}/complete ─────────► todas as partes; tamanho; assinatura; SHA-256 do ───► uploads/{id}.dem
   │                                  arquivo; repetido? ; status completed (24 h)
   │ DELETE /…/{id} ────────────────► cancela; apaga o arquivo ───────────────────────────► (nada)
```

Partes como corpo binário (`application/octet-stream`), não `multipart/form-data`: sem arquivo
temporário do PHP, sem nome de arquivo vindo do cliente no caminho. Todas as escritas com sessão e
CSRF, pelo `apiClient` (mesma origem, proxy `/api` do Next).

| Ponto | Classificação | Fonte |
|---|---|---|
| Nginx da API aceita corpo até 100 MB; PHP `post_max_size` 100 MB, `memory_limit` 256 MB | **Confirmada** | ambiente do container `api` (`NGINX_CLIENT_MAX_BODY_SIZE`, `PHP_POST_MAX_SIZE`) |
| A borda de referência de produção limita o corpo a **2 MB** (`client_max_body_size 2m`) | **Confirmada** | `infra/deploy/edge/nginx.conf.example`, linha 35; ver **U1** |
| O proxy `/api` do Next (rewrite) repassa uma parte de 8 MiB sem cortar nem bufferizar tudo | **Confirmada** | `scripts/matches-upload-probe.sh` na stack E2E: três partes (8 MiB, 8 MiB, resto) em ~240 ms cada, hash igual no disco |
| Demos do CS2 (Source 2) começam com os 8 bytes `PBDEMS2\0` | **Confirmada** (fontes públicas) | o `demo_file_stamp` de uma demo do CS2 aparece como `PBDEMS2` seguido de um byte nulo no [awpy](https://awpy.readthedocs.io/en/latest/examples/parse_demo.html), e o parser [Boon](https://boon.readthedocs.io/en/latest/internals/parsing-flow.html) confere esses bytes antes de ler o arquivo; conferir com uma demo do responsável continua opcional |
| Não há agendador (`schedule:work`) na stack; há um worker de filas (`queue:work`, Redis) | **Confirmada** | `compose.yaml`; ver **U2** |

## Regras

Em ordem; qualquer falha encerra sem gravar nada (ou apaga o que já existia, quando indicado) e com
um `code` estável no corpo `{message, code}`.

**Início** (`POST /api/v1/matches/uploads`, corpo `{ name, size }`)

| # | Regra | Falha → `code` |
|---|---|---|
| I1 | Sessão (`auth:sanctum`) e CSRF | 401 / 419 |
| I2 | Conta Steam vinculada | 409 `steam_link_required` |
| I3 | `size` inteiro entre 1 byte e 600 MiB; `name` texto de até 255, terminando em `.dem` (só para exibir; nunca vira caminho) | 422 `validation_failed` |
| I4 | Nenhum envio aberto do usuário (único parcial no banco decide a disputa) | 409 `match_upload_already_open` |
| I5 | Cota: menos de 5 envios **concluídos** nas últimas 24 h (D-M3) | 429 `match_upload_quota_exceeded` (com `Retry-After`) |
| I6 | Espaço livre no disco ≥ `size` + reserva de 2 GiB | 503 `match_upload_storage_unavailable` |

Resposta 201: `{ upload: { id, status, part_size, parts_total, parts_received, expires_at } }`.
`part_size` é decidido pela API (U1), `parts_total = ceil(size / part_size)`.

**Parte** (`PUT /api/v1/matches/uploads/{upload}/parts/{number}`, corpo binário, header
`X-Content-SHA256` com o SHA-256 da parte em hexadecimal)

| # | Regra | Falha → `code` |
|---|---|---|
| P1 | Sessão e CSRF; envio do próprio usuário (de outro ou inexistente: 404, sem revelar) | 401 / 419 / 404 |
| P2 | Envio em `uploading` e não expirado | 409 `match_upload_not_open` |
| P3 | `number` = partes recebidas + 1. Repetir a **última** recebida com o mesmo SHA-256 responde 200 sem reescrever (retentativa depois de falha de rede); com outro SHA-256, ou qualquer outro número | 409 `match_upload_part_out_of_order` |
| P4 | `Content-Length` = `part_size` (a última parte: o resto exato), conferido **antes** de ler o corpo | 422 `match_upload_part_size_invalid` |
| P5 | SHA-256 do corpo = `X-Content-SHA256` | 422 `match_upload_part_corrupted` |

Escrita no offset `(number − 1) × part_size` de `uploads/{id}.part`, com a linha do envio travada
(`FOR UPDATE`): duas partes simultâneas do mesmo envio são serializadas. Cada parte aceita renova
`last_activity_at`.

**Conclusão** (`POST /api/v1/matches/uploads/{upload}/complete`)

| # | Regra | Falha → `code` |
|---|---|---|
| C1 | Sessão, CSRF, posse (P1); envio em `uploading` (linha travada: duas conclusões simultâneas → uma conclui, a outra recebe 409) | 409 `match_upload_not_open` |
| C2 | Todas as partes recebidas | 409 `match_upload_incomplete` |
| C3 | Conta Steam ainda vinculada | 409 `steam_link_required` |
| C4 | Tamanho do arquivo no disco = `size` declarado | 422 `match_upload_size_mismatch`, arquivo apagado |
| C5 | Assinatura `PBDEMS2\0` nos 8 primeiros bytes (U4) | 422 `match_upload_not_a_demo`, arquivo apagado |
| C6 | SHA-256 do arquivo inteiro (lido em blocos) diferente dos envios **concluídos** do mesmo usuário (ADR 0022, item 5) | 409 `match_upload_duplicate`, arquivo apagado |

Sucesso: `uploads/{id}.part` vira `uploads/{id}.dem` (rename atômico no mesmo disco), status
`completed`, `expires_at` = agora + 24 h (D-M4: sem análise em 24 h, o arquivo é apagado). Na M4, o
mesmo ponto passa a enfileirar a análise.

**Cancelamento** (`DELETE /api/v1/matches/uploads/{upload}`): posse; envio `uploading` ou
`completed`; apaga o arquivo e marca `cancelled` (204). **Consulta** (`GET …/{upload}`): o estado,
para retomar um envio (partes recebidas) e, na M5, acompanhar.

## Banco

Migration `…_create_match_uploads_table` (módulo `Matches`):

| Coluna | Tipo | Regra |
|---|---|---|
| `id` | uuid (UUIDv7) | PK; também o nome do arquivo no disco |
| `user_id` | uuid | FK `users` **CASCADE** |
| `status` | varchar(16) | check: `uploading`, `completed`, `rejected`, `cancelled`, `expired` |
| `name` | varchar(255) | nome original, só para exibir |
| `size_bytes` | bigint | check `1 ≤ size_bytes ≤ 629145600` (600 MiB) |
| `part_size`, `parts_total`, `parts_received` | integer | check `0 ≤ parts_received ≤ parts_total` |
| `last_part_sha256` | char(64), nulo | para a retentativa idempotente da última parte (P3) |
| `sha256` | char(64), nulo | do arquivo inteiro, gravado na conclusão; check de hexadecimal |
| `rejection_code` | varchar(40), nulo | só em `rejected` (check) |
| `last_activity_at`, `expires_at` | timestamptz | |
| `completed_at` | timestamptz, nulo | só em `completed` e depois (check) |
| `created_at`, `updated_at` | timestamptz | |

Índices: **único parcial** `(user_id) WHERE status = 'uploading'` (um envio aberto por usuário,
I4); `(user_id, created_at DESC)` (cota e listagem); **único parcial** `(user_id, sha256) WHERE
status = 'completed'` (C6, também contra corrida). Nada de outro jogador é guardado.

## Disco e ciclo de vida do arquivo

- Disco `matches-uploads` em `config/filesystems.php`: local, `storage/app/matches-uploads`, **sem**
  `serve` nem URL pública. Caminhos só a partir do `id` (UUID): nenhum dado do cliente entra no
  caminho. Na stack E2E, `tmpfs`, como o disco `academy`.
- Estados: `uploading` → `completed` | `rejected` | `cancelled` | `expired`; `completed` →
  `cancelled` | `expired` (e, na M4, os estados da análise). Em `rejected`, `cancelled` e `expired`
  o arquivo não existe mais.
- **Expiração** (U2): ao criar o envio, um job atrasado (`ExpireMatchUpload`, fila Redis existente)
  confere depois de 2 h sem atividade; se houve atividade, se reagenda para o restante. Ao concluir,
  outro job para as 24 h. O job é idempotente e confere o estado antes de agir.
- **Rede de segurança**: `matches:prune-uploads` (mesmo padrão do `academy:media-prune`) expira o que
  passou do prazo e apaga arquivos do disco sem linha correspondente ou com linha fechada, com mais
  de 24 h, reconferindo cada um logo antes de remover. Roda à mão hoje; num agendador quando houver.
- Excluir a conta apaga as linhas (CASCADE); os arquivos restantes são órfãos que o
  `matches:prune-uploads` remove.

## API (módulo `Matches`)

| Método | Rota | Limite | Resposta |
|---|---|---|---|
| POST | `/api/v1/matches/uploads` | `match-upload` (30/min por usuário) | 201 `{ upload }` |
| PUT | `/api/v1/matches/uploads/{upload}/parts/{number}` | `match-upload-parts` (120/min por usuário) | 200 `{ upload }` |
| POST | `/api/v1/matches/uploads/{upload}/complete` | `match-upload` | 200 `{ upload }` |
| DELETE | `/api/v1/matches/uploads/{upload}` | `match-upload` | 204 |
| GET | `/api/v1/matches/uploads/{upload}` | `api` | 200 `{ upload }` |

Todas com `auth:sanctum`, CSRF nas escritas e `throttle:`; nenhuma na lista pública do
`RouteAuthorizationTest`. `MatchUploadPolicy` por posse (papéis de plataforma não abrem envios de
outros); recurso alheio responde **404**, como no Learning. Recurso `MatchUploadResource` com lista
fechada de campos (sem caminho no disco, sem `sha256` completo).

Arquivos previstos:

| Arquivo | Papel |
|---|---|
| `app/Modules/Matches/MatchesServiceProvider.php`, `routes.php` | módulo novo, rotas, limitadores |
| `app/Modules/Matches/Models/MatchUpload.php`, `Enums/MatchUploadStatus.php` | model (nada preenchível em massa) e estados |
| `app/Modules/Matches/Actions/{StartMatchUpload,StoreMatchUploadPart,CompleteMatchUpload,CancelMatchUpload}.php` | casos de uso, com a linha travada |
| `app/Modules/Matches/Uploads/{UploadStorage,DemoSignature}.php` | escrita no offset, rename, remoção; conferência da assinatura |
| `app/Modules/Matches/Jobs/ExpireMatchUpload.php`, `Console/PruneMatchUploadsCommand.php` | expiração e rede de segurança |
| `app/Modules/Matches/Exceptions/MatchUploadFailed.php` | `{message, code}` com status próprio, como `SteamLinkFailed` |
| `app/Modules/Matches/Http/Controllers/MatchUploadController.php`, `Http/Requests/StartMatchUploadRequest.php`, `Http/Resources/MatchUploadResource.php`, `Policies/MatchUploadPolicy.php` | HTTP e autorização |
| `config/filesystems.php` (disco), `config/matches.php` (limites: 600 MiB, `part_size`, cota, prazos, reserva de disco) | configuração |
| `database/migrations/…_create_match_uploads_table.php`, `database/factories/Matches/MatchUploadFactory.php` | |
| `compose.e2e.yaml` | `tmpfs` para o disco na stack E2E |

## Frontend

Nenhuma tela na M3: o envio pelo navegador (partes, progresso, retomar, cancelar) é da M5. Os
contratos acima já preveem o que ela precisa (SHA-256 por parte com `crypto.subtle`, retentativa
idempotente da última parte, consulta para retomar).

## Testes

**Pest (Feature e Unit)**, com arquivos sintéticos (assinatura + bytes aleatórios; nenhuma demo real
no repositório, porque demos têm dados de outros nove jogadores):

| Grupo | Casos |
|---|---|
| Início | sem sessão 401; sem CSRF 419; sem vínculo Steam 409; tamanho 0, negativo, acima de 600 MiB, não inteiro; nome sem `.dem`; envio já aberto 409; cota (5 concluídos em 24 h → 429 com `Retry-After`, cancelados e recusados não contam); disco sem espaço 503; `part_size` e `parts_total` corretos; expiração agendada |
| Partes | fora de ordem (pular, voltar); última repetida com o mesmo hash → 200 sem reescrever; repetida com outro hash → 409; `Content-Length` maior, menor, última parte com resto errado (recusada antes de ler); hash não confere; envio de outro usuário 404; envio concluído, cancelado ou expirado 409; o arquivo no disco tem exatamente os bytes enviados, no offset certo |
| Conclusão | sem todas as partes 409; tamanho no disco diferente; sem a assinatura (arquivo de texto renomeado, ZIP, assinatura no meio) → recusado e apagado; repetido do mesmo usuário 409 e apagado (de outro usuário: aceito); vínculo desfeito no meio → 409; sucesso: `.dem` no lugar do `.part`, `sha256` gravado, expira em 24 h |
| Cancelamento e expiração | cancelar apaga o arquivo; job de expiração depois de 2 h sem atividade e depois de 24 h concluído; job com atividade recente se reagenda; job repetido não faz nada; `matches:prune-uploads` remove órfãos antigos e nunca um arquivo de envio aberto; nada sobra no disco em nenhum caminho |
| Autorização e arquitetura | `RouteAuthorizationTest`; matriz de papéis (`user`, `editor`, `admin`) só com os próprios envios; `Matches` não usa `Academy`, `Learning` nem `Teams`, e os models dele só são usados no próprio módulo |
| Banco | migration: checks, únicos parciais, CASCADE, rollback |

**Concorrência** (`tests/Concurrency`, processos reais): duas conclusões simultâneas do mesmo envio
(uma conclui, a outra 409, o arquivo é lido e renomeado uma vez); dois inícios simultâneos do mesmo
usuário (um envio aberto); duas partes simultâneas com o mesmo número (uma escrita).

**Verificação pelo proxy** (stack E2E): sondagem versionada em `scripts/` que envia um arquivo
sintético de ~20 MiB em partes pelo `http://localhost:3000/api` (Next → API), conclui e confere o
hash, além de uma parte acima do limite e uma sem CSRF. Confirma o ponto "provável" do proxy.

## Segurança (resumo)

| Ameaça | Controle |
|---|---|
| Esgotar o disco | 600 MiB por arquivo, um envio aberto por usuário, cota diária, reserva de espaço, expiração em 2 h sem atividade e 24 h depois de concluído, limpeza de órfãos |
| Esgotar memória ou CPU da API | partes de tamanho fixo conferido pelo `Content-Length` antes da leitura; hash do arquivo lido em blocos; limites por minuto |
| *Path traversal* ou nome malicioso | caminho só a partir do UUID; o nome enviado só é exibido |
| Arquivo que não é demo | assinatura conferida na conclusão; o conteúdo só é interpretado pelo analisador isolado (M4) |
| Ver, continuar ou cancelar o envio de outro usuário | `MatchUploadPolicy` por posse; 404 para recurso alheio (o UUIDv7 tem 74 bits aleatórios, mas a posse é o que protege) |
| CSRF (fazer a vítima enviar ou cancelar) | sessão + `X-XSRF-TOKEN` em todas as escritas |
| Corrida (duas conclusões, dois inícios, partes simultâneas) | linha travada e únicos parciais no banco; testes de concorrência |
| Dados de terceiros | a demo só fica no disco até a análise ou o prazo; nada dela vai para o banco ou para logs; a M3 nem lê o conteúdo além da assinatura |

## Documentação a atualizar na implementação

[visao-geral.md](../architecture/visao-geral.md) (módulo `Matches`), [modelo-conceitual.md](../database/modelo-conceitual.md)
(`match_uploads`), [convencoes.md](../api/convencoes.md) (endpoints e códigos),
[autorizacao.md](../architecture/autorizacao.md) (Policy), [testes-de-concorrencia.md](../development/testes-de-concorrencia.md),
[e2e.md](../development/e2e.md) (disco em `tmpfs`, sondagem), [bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md)
(se U1 mudar a borda), o [plano do MVP](mvp-analise-de-demos.md), este plano (execução) e a
[ADR 0022](../decisions/0022-analise-de-partidas-por-demos.md).

## Critérios de aceite

- Todas as regras com teste que falha se a regra for removida (contraprova em pelo menos 3: ordem
  das partes (P3), hash da parte (P5) e assinatura (C5)).
- Pest, concorrência, Vitest, lint, build e E2E verdes; nenhuma rota nova sem autenticação ou limite.
- Sondagem pelo proxy da stack E2E: arquivo montado com o hash certo.
- Nada sobra no disco depois de cancelar, recusar ou expirar (conferido nos testes e na sondagem).
- Migration no banco de desenvolvimento só com o procedimento de sempre (backup, `--pretend`,
  aprovação).

## Decisões

Aprovadas pelo responsável em 2026-10-25, como propostas (o plano foi aprovado; a implementação
continua exigindo autorização própria).

| # | Decisão | Situação |
|---|---|---|
| U1 | Tamanho das partes × borda de produção (2 MB) | **Aprovada**: partes de 8 MiB (D-M3) e, na borda de referência, uma exceção só para `PUT /api/v1/matches/uploads/*/parts/*` com `client_max_body_size 9m`, mantendo 2 MB no resto (mudança da borda aprovada para esta rota; registrar em [bloqueios-de-producao.md](../deploy/bloqueios-de-producao.md) na implementação) |
| U2 | Limpeza sem agendador | **Aprovada**: jobs atrasados na fila existente (`ExpireMatchUpload`) mais o comando `matches:prune-uploads` como rede de segurança; sem serviço `scheduler` novo |
| U3 | Limites (D-M3) | **Aprovada**: 600 MiB por arquivo, um envio aberto, **5 envios concluídos por 24 h**, expiração em 2 h sem atividade, reserva de 2 GiB de disco; limites por minuto de 30 (envio) e 120 (partes). Revisar na M6 |
| U4 | Conferência do conteúdo na M3 | **Aprovada**: só assinatura e tamanho; a leitura do cabeçalho (mapa, servidor, build) fica com o analisador isolado da M4. A ADR 0022 (item 3) cita o cabeçalho antes da fila; ler protobuf de arquivo do usuário dentro da API iria contra o isolamento do item 4 |
| U5 | Envio repetido | **Aprovada**: recusar o mesmo arquivo (SHA-256) **do mesmo usuário** enquanto houver envio concluído ou análise dele; o mesmo arquivo de outro jogador da partida é aceito (cada um analisa a própria participação) |
| U6 | Envio concluído antes de a M4 existir | **Aprovada**: fica `completed` e expira em 24 h (arquivo apagado), como previsto em D-M4; na M4 a conclusão passa a enfileirar a análise |

## Execução (2026-10-25)

Implementado como planejado, com estes ajustes:

| Ponto do plano | Implementado | Motivo |
|---|---|---|
| Recusa do arquivo como `match_upload_rejected` + `rejection_code` | O motivo vai no próprio `code` (`match_upload_size_mismatch`, `match_upload_not_a_demo`, `match_upload_duplicate`); o envio guarda `rejection_code` e a consulta o mostra | O formatador de erros da API (`ApiErrorResponse`) mantém só `message`, `code` e `errors` |
| Expiração por job | Um job atrasado no início, a cada parte e na conclusão; ele não se reagenda e só age se o prazo **gravado** venceu | Evita laço na fila síncrona dos testes; um job antigo, de antes de uma parte nova, não faz nada |
| `Retry-After` da cota | Arredondado para cima | O banco guarda os horários sem fração de segundo; o cliente não deve tentar antes da hora |
| Disco | `MATCHES_UPLOADS_ROOT` opcional (padrão `storage/app/matches-uploads`) | Pasta temporária nos testes de concorrência; volume próprio em produção |
| Stack E2E | `tmpfs` só no `api` | Os jobs de expiração do worker vencem em horas, depois de a stack ser derrubada. Em produção, `api` e `worker` precisam do mesmo volume (registrado em P4) |
| Sondagem | Conta `matches-probe@e2e.test`, criada pelo `e2e:prepare` já com Steam vinculada | O vínculo pelo fluxo OpenID já tem E2E próprio (M2) |
| Testes de rollback | Contam as migrations posteriores pela tabela `migrations` (`migrationsFrom()` em `tests/Pest.php`) | Cada migration nova quebrava cinco testes por número fixo |

| Critério de aceite | Resultado |
|---|---|
| Regras com teste; contraprova em P3, P5 e C5 | `MatchUploadTest` (43 testes). Contraprovas: sem a ordem das partes, 1 falha; sem o hash da parte, 3; sem a assinatura, 4. Arquivos restaurados |
| Pest, concorrência, Vitest, lint, build e E2E verdes; nenhuma rota sem autenticação ou limite | ver o PR; `MatchUploadConcurrencyTest` (3 casos) |
| Sondagem pelo proxy da stack E2E | `matches-upload-probe.sh`: 9/9 e o hash no disco igual ao enviado |
| Nada sobra no disco depois de cancelar, recusar ou expirar | testes de cancelamento, recusa, expiração e `matches:prune-uploads`; sondagem (só o concluído no disco) |
| Migration no banco de desenvolvimento com o procedimento | **pendente** (autorização própria) |
