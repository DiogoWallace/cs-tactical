# Desempenho: linha de base e limites de regressão

Etapa 2.7d ([plano](fase-2-etapa-2.7-plano.md#27d--desempenho)). Mede a API e as páginas da Academy
e do Learning em dois cenários de dados, numa stack descartável, e propõe limites de regressão a
partir da variabilidade observada. Os limites da última seção foram aprovados em 2026-10-24.

## Como repetir

```bash
make perf                       # ou ./scripts/perf.sh
PERF_SAMPLES=30 make perf       # mais amostras por endpoint (padrão 20)
PERF_OPCACHE=0 make perf        # API sem OPcache, como a imagem de desenvolvimento
PERF_KEEP=1 make perf           # mantém a stack no ar ao final (derrube com make e2e-down)
```

Leva cerca de 16 minutos e usa o projeto Compose da stack E2E (`cs-tactical-e2e`): não rode junto
com `make e2e`. O script sobe uma stack nova, mede o cenário pequeno, gera e importa o ampliado,
mede de novo e derruba tudo. Resultados em `.perf/<data-hora>/` (fora do Git): JSON brutos, log de
acesso da API, `ambiente.md` e `summary.md` com as tabelas abaixo.

| Arquivo | O que faz | Onde roda |
|---|---|---|
| `scripts/perf.sh` | orquestra, registra ambiente e carga antes e depois | WSL/CI |
| `scripts/perf/generate-academy.php` | gera os 50 cursos em `/tmp` do container | container `api` |
| `scripts/perf/seed-players.php` | contas `perf-1..6@e2e.test` e histórico pelas Actions do Learning | container `api` |
| `scripts/perf/api-queries.php` | consultas por endpoint, tempo em processo e `EXPLAIN (ANALYZE, BUFFERS)` | container `api` |
| `scripts/perf/http.mjs` | tempos por HTTP da API e das páginas do Next | container `web` |
| `scripts/perf/summary.py` | junta tudo em Markdown e cruza as páginas com o log de acesso | WSL/CI |
| `scripts/perf/compose.opcache.yaml` | liga o OPcache só na API da stack de medição | Compose |

Os três scripts PHP recusam qualquer banco que não seja o `cs_tactical_e2e` com `APP_ENV=e2e`. Nada
é gerado no banco de desenvolvimento nem no conteúdo real.

## Ambiente

Máquina de desenvolvimento (WSL2): 12 CPUs, 11,9 GB de RAM (~9,4 GB livres). Stack E2E: build de
produção do Next, API na imagem `development` (Nginx + PHP-FPM) **com OPcache ligado pelo override**,
Postgres 18 e Redis em `tmpfs`. Carga registrada em cada execução (`ambiente.md`): load average
entre 1,1 e 2,1, exceto o início da segunda execução "depois" (10,5, logo após o build da anterior).

**Por que OPcache.** A imagem de desenvolvimento desliga o OPcache (`PHP_OPCACHE_ENABLE: 0` no
`compose.yaml`) para recarregar o código a cada edição. Medido assim (execução de referência sem
OPcache), toda requisição HTTP à API leva 135–155 ms (p50), inclusive as triviais que custam 5 ms
no processo: o número mede a recompilação do framework, não a aplicação. **A imagem de produção
precisa de OPcache ligado** (registrar quando a imagem de produção for criada).

## Cenários

| Tabela | Pequeno | Ampliado |
|---|---|---|
| cursos | 4 | 54 |
| módulos | 6 | 256 |
| aulas | 9 | 2.009 |
| quizzes | 1 | 251 |
| matrículas (todos os jogadores) | 6 | 35 |
| progresso de aula | 6 | 469 |
| tentativas de quiz | 10 | 99 |

- **Pequeno**: o conteúdo de E2E (`apps/web/e2e/fixtures/academy`) e o jogador `perf-1` matriculado
  em `fundamentos-e2e`, com uma aula concluída e uma tentativa.
- **Ampliado** (decisão R3): + 50 cursos × 5 módulos × 8 aulas, com a **mesma forma** do curso de
  fixture (capa, imagem e callout em cada aula, quiz de 2 perguntas na primeira aula de cada
  módulo), para as contagens serem comparáveis. `perf-1` matriculado em 20 cursos, com as 2N
  primeiras aulas do curso N concluídas (420) e 80 tentativas nos cursos 1–10. Alvos medidos: curso
  `perf-curso-10`, aula `m3-aula-1`, quiz `perf-curso-10-m1-quiz`.

## Método

- **Consultas e tempo em processo** (`api-queries.php`): cada requisição passa pelo kernel HTTP da
  API (rotas, middlewares, controllers) com o jogador autenticado no guard `web`, como o `actingAs`
  dos testes Pest; as contagens são comparáveis às já testadas e não incluem a leitura da sessão.
  Mediana de 5 execuções após 1 de aquecimento. Escritas: uma vez cada, com `perf-6` (primeiro
  acesso: matrícula nova, conclusão nova, tentativa nova).
- **HTTP** (`http.mjs`): direto na API (`http://api:8080`, sem o Next), com sessão real; 3 de
  aquecimento + 20 amostras (escritas: 10; tentativas: 8, uma por quiz novo). Matrícula e conclusão
  repetem o mesmo alvo (caminho idempotente, comparável entre cenários).
- **Páginas**: `http://localhost:3000` (build de produção), visitante e logado; TTFB (headers) e
  fim do corpo (todo o streaming); 2 + 10 amostras. Chamadas à API por página: uma carga isolada de
  cada página, contada no log de acesso do Nginx da API pelo horário que ele grava (resolução de
  segundo, janelas a 3 s uma da outra). Conta só as chamadas feitas pelo servidor do Next; as do
  navegador depois da hidratação não entram.
- **Limites de requisição intactos**: o script espaça as chamadas (700 ms; tentativas 3 s; páginas
  1,5 s) e espera 61 s entre fases para ficar abaixo de `academy-public`, `api`, `learning-write` e
  `quiz-attempt`. Todas as respostas medidas foram 2xx.

## Resultados

Execuções de 2026-10-24 sobre a `main` em `357c8a8` com as alterações desta etapa: duas depois da
correção ([achado D1](#achados)), com os valores separados por barra, e uma "antes", com o mesmo
método e sem a correção.

### API

| Endpoint | Consultas (P = A) | Em processo P (ms) | Em processo A (ms) | HTTP p50 P | HTTP p50 A | HTTP p95 A |
|---|---|---|---|---|---|---|
| catálogo | 3 | 8,6 / 5,9 | 11,8 / 10,3 | 44,5 / 42,8 | 49,7 / 48,9 | 64,8 / 83,6 |
| curso | 4 | 8,3 / 6,0 | 12,2 / 9,1 | 39,6 / 40,6 | 41,1 / 43,6 | 47,6 / 58,1 |
| aula | 6 | 9,6 / 7,0 | 11,3 / 10,3 | 41,6 / 45,3 | 44,1 / 44,8 | 53,4 / 63,2 |
| `/progress` | 5 | 9,0 / 6,4 | 12,7 / 10,6 | 35,9 / 48,5 | 45,7 / 49,8 | 51,6 / 61,8 |
| `/me/enrollments` | 4 | 7,0 / 4,7 | **83,1 / 72,0** (antes 162,0) | 36,3 / 45,9 | **92,8 / 106,3** (antes 173,4) | 133,1 / 140,1 (antes 202,4) |
| `/me/quiz-attempts` | 3 | 6,2 / 4,2 | 5,1 / 5,2 | 34,8 / 39,2 | 31,3 / 40,5 | 39,8 / 48,5 |
| tentativa | 4 | 6,0 / 4,4 | 5,8 / 7,1 | 34,5 / 41,8 | 37,6 / 39,8 | 48,3 / 44,4 |
| matrícula | 4 | 17,9 / 14,1 | 24,1 / 21,9 | 32,3 / 38,3 | 37,4 / 38,3 | 59,6 / 48,4 |
| conclusão | 13 | 21,6 / 12,5 | 44,4 / 21,3 | 39,8 / 48,5 | 51,8 / 52,8 | 73,8 / 55,3 |
| início de tentativa | 18 | 29,5 / 21,3 | 38,7 / 29,7 | 54,9 / 60,1 | 57,8 / 56,7 | 103,2 / 72,8 |
| envio de tentativa | 15 | 22,4 / 15,0 | 32,1 / 19,6 | 52,8 / 62,9 | 59,7 / 59,1 | 108,2 / 65,5 |

- **Contagem de consultas igual nos dois cenários em todos os endpoints** e igual às já testadas
  (catálogo 3, curso 4, aula 6, `/progress` 5, `/me/enrollments` 4). Curso e aula têm uma consulta
  de mídia que só existe quando o conteúdo tem capa ou imagem (por isso o ampliado tem a mesma
  forma da fixture). O envio de tentativa grava uma resposta por pergunta: 15 com 2 perguntas, +1
  por pergunta (limite de 30 na validação).
- HTTP ≈ tempo em processo + ~30–35 ms de Nginx, PHP-FPM, sessão (Redis) e usuário.

### Páginas do Next

| Página | Chamadas à API | TTFB p50 | Total p50 P (ms) | Total p50 A (ms) |
|---|---|---|---|---|
| `/academy` (visitante) | 1: catálogo | 3,8–4,6 ms | 60,7 / 58,1 | 76,3 / 68,2 |
| curso (visitante) | 1: curso | 3,7–5,1 ms | 63,3 / 56,1 | 86,3 / 67,3 |
| aula (visitante) | 2: curso, aula | 3,9–5,5 ms | 80,2 / 59,3 | 85,2 / 74,1 |
| `/academy` (logado) | 3: catálogo, `/me`, `/me/enrollments` | 3,5–5,4 ms | 75,2 / 57,5 | 151,6 / 136,8 (antes 242,0) |
| curso (logado) | 3: curso, `/progress`, `/me` | 3,8–5,3 ms | 83,6 / 58,5 | 84,7 / 78,8 |
| aula (logado) | 5: curso, aula, `/progress`, `/me`, `/me/quiz-attempts` | 3,9–5,2 ms | 129,6 / 93,1 | 137,5 / 137,4 |
| `/painel` | 2: `/me`, `/me/enrollments` | 4,4–5,3 ms | 67,0 / 51,2 | 121,0 / 121,6 (antes 183,6) |
| `/painel/academy` | 2: `/me`, `/me/enrollments` | 4,3–7,3 ms | 58,5 / 50,4 | 159,3 / 127,7 (antes 215,8) |

- O TTFB baixo é o shell estático (Cache Components/PPR); o total é o fim do streaming, dominado
  pelas chamadas à API em sequência.
- Visitante sem cookie não chama `/me` (etapa 2.6). As páginas logadas que usam `/me/enrollments`
  são as que mais crescem com o histórico do jogador.

### Banco (`EXPLAIN (ANALYZE, BUFFERS)`, ampliado)

Nenhuma consulta passou de 3 ms. As mais caras: a listagem do catálogo (2,1–2,8 ms, subconsultas
correlacionadas de contagem de módulos, aulas e minutos para 20 cursos) e as aulas públicas dos
cursos matriculados (1,1–1,2 ms, 800 linhas). As demais ficam abaixo de 0,5 ms, tudo em cache
(nenhum bloco lido do disco). Os índices de [academy-learning.md](../database/academy-learning.md)
são usados (`lessons_course_id_status_index`, `enrollments_user_id_last_activity_at_index`,
`lesson_progress_user_id_course_id_index`); as varreduras sequenciais são em tabelas pequenas
(`courses` 54, `course_modules` 256, `quizzes` 251 linhas), onde o planejador as prefere. Nenhuma
migration de índice se justifica.

### E2E na CI

CI da `main` em `357c8a8` (55 testes no `chromium-desktop`): mediana 2,4 s, p95 9,1 s, máximo
11,3 s (acessibilidade do Learning), contra o limite de 30 s por teste: folga de ~2,7×. Suíte em
1,7 min. Alarme do `academy-public` na mesma execução: pico de 74/120 (local: 58–63).

## Achados

| ID | Tipo | Achado | Tratamento |
|---|---|---|---|
| D1 | **Comprovado** | `ProgressQuery::enrollments()` filtrava a coleção de todas as aulas para cada matrícula: custo matrículas × aulas. Ampliado (20 matrículas, 800 aulas): 162 ms em processo, 173 ms HTTP p50; páginas logadas com `/me/enrollments` ~110 ms mais lentas | **Corrigido**: aulas e conclusões agrupadas por curso uma vez. Depois: 72–83 ms em processo, 93–106 ms HTTP p50, mesmas 4 consultas. Teste novo (curso arquivado ao lado de um publicado, com contraprova) |
| H1 | Hipotético | O resto do `/me/enrollments` é linear nas aulas dos cursos matriculados: hidratar 800 aulas Eloquent (14 ms; 4 ms sem hidratar), 452 progressos (9 ms) e montar os itens (~22 ms) | Registrar. Só com medição que justifique: leitura sem hidratação ou contagem no SQL |
| H2 | Hipotético | Envio de tentativa grava uma resposta por pergunta (+1 consulta por pergunta, até 30) | Registrar; inserção em lote se quizzes grandes aparecerem |
| H3 | Hipotético | Catálogo com subconsultas correlacionadas (2–3 ms com 54 cursos, 20 por página) | Registrar; nada a fazer nessa escala |
| R-OP | Recomendação | Sem OPcache, cada requisição custa ~100 ms a mais | Imagem de produção com OPcache ligado |

## Variabilidade

Entre as duas execuções "depois": HTTP p50 das leituras dentro de ±15%; p95 varia até ±40% (20
amostras numa máquina compartilhada); tempo em processo das escritas varia até 2× (conclusão no
ampliado: 44 e 21 ms), sensível à carga do início da segunda execução. Por isso os limites abaixo
usam p50 e contagem de consultas, não p95.

## Limites de regressão (aprovados em 2026-10-24)

1. **Consultas: exatamente as medidas**, iguais nos cenários pequeno e ampliado (catálogo 3, curso 4,
   aula 6, `/progress` 5, `/me/enrollments` 4, `/me/quiz-attempts` 3, tentativa 4, matrícula 4,
   conclusão 13, início de tentativa 18, envio 15 com 2 perguntas). Já protegidas nos testes Pest
   onde existiam; qualquer diferença entre cenários no `make perf` é regressão.
2. **Tempo HTTP p50 no ampliado**: maior p50 das duas execuções + 30%, arredondado para cima em 5 ms.

   | Endpoint | Limite | Página (total p50) | Limite |
   |---|---|---|---|
   | catálogo | 65 ms | `/academy` visitante | 100 ms |
   | curso | 60 ms | curso visitante | 115 ms |
   | aula | 60 ms | aula visitante | 115 ms |
   | `/progress` | 65 ms | `/academy` logado | 200 ms |
   | `/me/enrollments` | 140 ms | curso logado | 115 ms |
   | `/me/quiz-attempts` | 55 ms | aula logado | 180 ms |
   | tentativa | 55 ms | `/painel` | 160 ms |
   | matrícula | 50 ms | `/painel/academy` | 210 ms |
   | conclusão | 70 ms | | |
   | início de tentativa | 80 ms | | |
   | envio de tentativa | 80 ms | | |

   Medidos com `make perf` na mesma máquina e conferidos à mão (a CI não roda o `make perf`).
   Passar de um limite numa execução pede uma segunda; nas duas, é regressão a investigar.
3. **E2E**: o limite atual de 30 s por teste continua; teste acima de 15 s na CI é sinal para
   investigar (hoje o máximo é 11,3 s).
