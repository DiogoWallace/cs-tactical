<?php

/*
| Consultas por endpoint e EXPLAIN (etapa 2.7d). Ver docs/development/desempenho.md.
|
| Roda DENTRO do container `api` da stack E2E, lendo o script pela entrada padrão, depois do
| scripts/perf/seed-players.php do mesmo cenário:
|
|   docker compose -p cs-tactical-e2e ... exec -T -e PERF_SCENARIO=ampliado api php < scripts/perf/api-queries.php
|
| Cada requisição passa pelo kernel HTTP da API (rotas, middlewares, controllers), no próprio
| processo, com o jogador já autenticado no guard `web`, como o `actingAs` dos testes Pest: as
| contagens são comparáveis às já testadas (catálogo 3/4/6, /progress 5, /me/enrollments 4) e não
| incluem a leitura da sessão. Tempo em processo: mediana de 5 execuções depois de 1 de aquecimento,
| sem Nginx nem PHP-FPM (o tempo por HTTP fica no scripts/perf/http.mjs).
|
| Com PERF_EXPLAIN=1, roda EXPLAIN (ANALYZE, BUFFERS) em cada SELECT das leituras.
| Saída: um JSON na saída padrão. Escritas usam perf-6, que fica com uma matrícula, uma conclusão e
| uma tentativa a mais (só na stack descartável).
*/

use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$scenario = getenv('PERF_SCENARIO') ?: '';
if (! in_array($scenario, ['pequeno', 'ampliado'], true)) {
    fwrite(STDERR, "PERF_SCENARIO precisa ser pequeno ou ampliado.\n");
    exit(2);
}
if (! app()->environment('e2e') || DB::scalar('select current_database()') !== 'cs_tactical_e2e') {
    fwrite(STDERR, "Recusado: só roda com APP_ENV=e2e no banco cs_tactical_e2e.\n");
    exit(2);
}
$explain = getenv('PERF_EXPLAIN') === '1';

// Pequeno: a fixture de E2E. Ampliado: curso 10 do perf-1 (20 aulas concluídas e tentativas).
$target = $scenario === 'pequeno'
    ? ['course' => 'fundamentos-e2e', 'lesson' => 'counter-strafe', 'quiz' => 'counter-strafe-e2e',
        'write_course' => 'fundamentos-e2e', 'write_lesson' => 'counter-strafe', 'write_quiz' => 'counter-strafe-e2e']
    : ['course' => 'perf-curso-10', 'lesson' => 'm3-aula-1', 'quiz' => 'perf-curso-10-m1-quiz',
        'write_course' => 'perf-curso-40', 'write_lesson' => 'm2-aula-3', 'write_quiz' => 'perf-curso-40-m1-quiz'];

$player = User::whereEmailIs('perf-1@e2e.test')->firstOrFail();
$writer = User::whereEmailIs('perf-6@e2e.test')->firstOrFail();
$attemptId = DB::table('quiz_attempts')->where('user_id', $player->getKey())->orderByDesc('started_at')->value('id');

/** @return array{status: int, ms: float, queries: list<array{sql: string, bindings: array}>, json: mixed} */
function call(Kernel $kernel, ?User $user, string $method, string $uri, ?array $body = null): array
{
    $request = Request::create($uri, $method, [], [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
    ], $body === null ? null : json_encode($body));

    // Guards novos a cada chamada: o `sanctum` guarda o usuário resolvido entre requisições do
    // mesmo processo. O `web` é criado a partir da requisição atual (o kernel a troca no handle()).
    Auth::forgetGuards();
    app()->instance('request', $request);
    if ($user !== null) {
        Auth::guard('web')->setUser($user);
    }

    $queries = [];
    DB::flushQueryLog();
    DB::enableQueryLog();
    $started = hrtime(true);
    $response = $kernel->handle($request);
    $ms = (hrtime(true) - $started) / 1e6;
    foreach (DB::getQueryLog() as $entry) {
        $queries[] = ['sql' => $entry['query'], 'bindings' => $entry['bindings']];
    }
    DB::disableQueryLog();
    $kernel->terminate($request, $response);

    return ['status' => $response->getStatusCode(), 'ms' => $ms, 'queries' => $queries, 'json' => json_decode((string) $response->getContent(), true)];
}

function median(array $values): float
{
    sort($values);
    $n = count($values);

    return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

/** Resumo do plano: tempos e nós, marcando varredura sequencial em tabela grande. */
function explainPlan(array $query): array
{
    $rows = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) '.$query['sql'], $query['bindings']);
    $plan = json_decode(array_values((array) $rows[0])[0], true)[0];
    $nodes = [];
    $walk = function (array $node) use (&$walk, &$nodes): void {
        $nodes[] = trim(($node['Node Type'] ?? '?').' '.($node['Relation Name'] ?? '').' '.($node['Index Name'] ?? ''))
            .sprintf(' (linhas %d)', $node['Actual Rows'] ?? 0);
        foreach ($node['Plans'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($plan['Plan']);

    return [
        'sql' => preg_replace('/\s+/', ' ', $query['sql']),
        'execution_ms' => $plan['Execution Time'],
        'planning_ms' => $plan['Planning Time'],
        'shared_hit' => $plan['Plan']['Shared Hit Blocks'] ?? null,
        'shared_read' => $plan['Plan']['Shared Read Blocks'] ?? null,
        'nodes' => $nodes,
    ];
}

$reads = [
    'catalogo' => [null, 'GET', '/api/v1/academy/courses'],
    'curso' => [null, 'GET', "/api/v1/academy/courses/{$target['course']}"],
    'aula' => [null, 'GET', "/api/v1/academy/courses/{$target['course']}/lessons/{$target['lesson']}"],
    'progresso' => [$player, 'GET', "/api/v1/academy/courses/{$target['course']}/progress"],
    'matriculas' => [$player, 'GET', '/api/v1/me/enrollments'],
    'tentativas_do_quiz' => [$player, 'GET', "/api/v1/me/quiz-attempts?quiz={$target['quiz']}"],
    'tentativa' => [$player, 'GET', "/api/v1/academy/quiz-attempts/{$attemptId}"],
    'me' => [$player, 'GET', '/api/v1/me'],
];

$result = ['scenario' => $scenario, 'target' => $target, 'reads' => [], 'writes' => [], 'explain' => []];

foreach ($reads as $name => [$user, $method, $uri]) {
    call($kernel, $user, $method, $uri);
    $runs = [];
    for ($i = 0; $i < 5; $i++) {
        $runs[] = call($kernel, $user, $method, $uri);
    }
    $last = end($runs);
    $result['reads'][$name] = [
        'uri' => $uri,
        'status' => $last['status'],
        'queries' => count($last['queries']),
        'in_process_ms_median' => round(median(array_column($runs, 'ms')), 2),
    ];
    if ($explain) {
        foreach ($last['queries'] as $index => $query) {
            if (preg_match('/^\s*select\b/i', $query['sql']) === 1) {
                $result['explain'][$name][$index] = explainPlan($query);
            }
        }
    }
}

// Escritas: uma vez cada, com perf-6 (sem histórico), na ordem natural do jogador.
$writes = [
    'matricula' => ['PUT', "/api/v1/academy/courses/{$target['write_course']}/enrollment", null],
    'conclusao' => ['PUT', "/api/v1/academy/courses/{$target['write_course']}/lessons/{$target['write_lesson']}/completion", null],
    'inicio_tentativa' => ['POST', "/api/v1/academy/quizzes/{$target['write_quiz']}/attempts", null],
];
foreach ($writes as $name => [$method, $uri, $body]) {
    $call = call($kernel, $writer, $method, $uri, $body);
    $result['writes'][$name] = ['uri' => $uri, 'status' => $call['status'], 'queries' => count($call['queries']), 'in_process_ms' => round($call['ms'], 2)];
    if ($name === 'inicio_tentativa') {
        $attempt = $call['json']['data'] ?? [];
        $answers = array_map(fn (array $q) => ['question_id' => $q['id'], 'option_ids' => [$q['options'][0]['id']]], $attempt['questions'] ?? []);
        $submit = call($kernel, $writer, 'POST', "/api/v1/academy/quiz-attempts/{$attempt['id']}/submission", ['answers' => $answers]);
        $result['writes']['envio_tentativa'] = ['uri' => '/api/v1/academy/quiz-attempts/{id}/submission', 'status' => $submit['status'], 'queries' => count($submit['queries']), 'in_process_ms' => round($submit['ms'], 2)];
    }
}

$result['data'] = [
    'courses' => DB::table('courses')->count(),
    'modules' => DB::table('course_modules')->count(),
    'lessons' => DB::table('lessons')->count(),
    'quizzes' => DB::table('quizzes')->count(),
    'users' => DB::table('users')->count(),
    'enrollments' => DB::table('enrollments')->count(),
    'lesson_progress' => DB::table('lesson_progress')->count(),
    'quiz_attempts' => DB::table('quiz_attempts')->count(),
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL;
