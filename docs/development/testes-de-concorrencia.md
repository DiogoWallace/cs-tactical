# Testes de concorrência

Testes em `apps/api/tests/Concurrency` (grupo Pest `concurrency`) que provam regras de
unicidade e de travamento com **disputa real**: dois processos PHP independentes, cada um com a
própria conexão ao PostgreSQL, chegando ao mesmo ponto ao mesmo tempo.

| Teste | Regra |
|---|---|
| `LastAdminDemotionTest` | rebaixar os dois últimos admins ao mesmo tempo deixa um admin (ADR 0012, `lockForUpdate`) |
| `LearningConcurrencyTest` — matrícula | duas matrículas simultâneas do mesmo jogador geram uma linha (`ON CONFLICT` no único `(user_id, course_id)`) |
| `LearningConcurrencyTest` — conclusão | duas conclusões simultâneas da mesma aula geram uma conclusão e uma matrícula (`ON CONFLICT` em `(user_id, lesson_id)`) |
| `MatchUploadConcurrencyTest` — conclusão | duas conclusões simultâneas do mesmo envio: uma conclui (arquivo conferido e renomeado uma vez), a outra recebe `match_upload_not_open` (linha travada; etapa M3) |
| `MatchUploadConcurrencyTest` — início | dois inícios simultâneos do mesmo jogador abrem um envio só (único parcial `status = 'uploading'`) |
| `MatchUploadConcurrencyTest` — parte | a mesma parte com conteúdos diferentes ao mesmo tempo: uma escrita inteira, a outra recusada (sem mistura no arquivo). Os processos gravam numa pasta temporária (`MATCHES_UPLOADS_ROOT`) |
| `SteamLinkConcurrencyTest` | dois usuários concluindo o vínculo do mesmo SteamID ao mesmo tempo: um grava, o outro recebe `steam_account_in_use` (único de `steam_id`; etapa M2). A sincronização é uma terceira conexão com o mesmo SteamID num INSERT ainda não confirmado |

## Como funcionam

- **Banco próprio e descartável** por teste (`cs_tactical_concurrency_<aleatório>`), criado a
  partir da conexão de testes (`cs_tactical_test`), migrado pelo próprio teste e apagado no fim
  (`DROP DATABASE ... WITH (FORCE)`, também em caso de falha). Nada é compartilhado com a suíte
  comum nem com o banco de desenvolvimento.
- **Processos independentes**: `Symfony\Component\Process` com `php artisan ...` (comando do
  Identity ou `tinker --execute` chamando a Action do Learning), apontados para o banco
  descartável por `DB_DATABASE`.
- **Sincronização determinística**: uma terceira conexão segura a trava que as Actions pedem
  (`FOR UPDATE` nas linhas de admin ou no curso); o teste só solta quando o `pg_stat_activity`
  mostra os **dois** processos esperando por ela. Tempo máximo de 20 s em cada espera.
- Sem `RefreshDatabase` (os processos não enxergariam a transação do teste) e **sem retry**: uma
  falha é uma corrida real ou uma regressão na trava.

Requisitos: PostgreSQL real com permissão de `CREATE DATABASE` para o usuário da aplicação,
extensão `pcntl` e as dependências de desenvolvimento do Composer (Pest, Tinker).

## Como rodar

Localmente (dentro do container `api`, banco `cs_tactical_test`):

```bash
make test-concurrency
```

Equivale a `composer test:concurrency`, que roda
`pest tests/Concurrency --group=concurrency --fail-on-empty-test-suite`. O
`--fail-on-empty-test-suite` faz o comando falhar se nenhum teste for encontrado (ex.: grupo
renomeado), em vez de passar sem testar nada.

Na CI: passo **Pest (concorrência)** do job **API (Laravel)**, depois do Pest comum, no mesmo
serviço PostgreSQL do job (cada teste com o seu banco descartável), com `timeout-minutes: 5`.
Uma falha reprova o job.

Para conferir que nada sobrou depois de uma execução:

```bash
docker compose exec postgres psql -U cs_tactical -d cs_tactical_test -tAc "select datname from pg_database where datname like 'cs_tactical_concurrency_%'"
```

## Contraprova

Um teste de concorrência só vale se falhar sem a proteção que ele cobre. Registrado na Fase 1
para o último admin (sem `lockForUpdate()`, a plataforma fica sem admin e o teste falha) e
repetido para o Learning: sem o `ON CONFLICT`, o segundo processo falha com violação de unicidade
e o teste reprova.
