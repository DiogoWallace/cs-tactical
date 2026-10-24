<?php

/*
| Jogadores da medição de desempenho (etapa 2.7d). Ver docs/development/desempenho.md.
|
| Roda DENTRO do container `api` da stack E2E, lendo o script pela entrada padrão, depois do
| `e2e:prepare` e das importações:
|
|   docker compose -p cs-tactical-e2e ... exec -T -e PERF_SCENARIO=pequeno api php < scripts/perf/seed-players.php
|
| Cria perf-1..perf-6@e2e.test (senha das contas E2E) e grava o estado pelas Actions públicas do
| Learning, como o `e2e:prepare --seed-history`:
| - pequeno: perf-1 matriculado em fundamentos-e2e, com a aula do quiz concluída e uma tentativa
|   reprovada (sobra uma);
| - ampliado: perf-1 matriculado em perf-curso-01..20; no curso N, as 2N primeiras aulas concluídas
|   (os cursos 20 em diante, completos); nos cursos 01..10, uma tentativa reprovada em cada quiz e,
|   nos módulos ímpares, uma segunda aprovada.
| perf-2..perf-6 ficam sem histórico: são usados nas medições de escrita.
|
| Recusa qualquer banco que não seja o cs_tactical_e2e com APP_ENV=e2e.
*/

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Actions\CompleteLesson;
use App\Modules\Learning\Actions\EnrollInCourse;
use App\Modules\Learning\Actions\StartQuizAttempt;
use App\Modules\Learning\Actions\SubmitQuizAttempt;
use App\Modules\System\Console\PrepareE2eDatabaseCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$scenario = getenv('PERF_SCENARIO') ?: '';
if (! in_array($scenario, ['pequeno', 'ampliado'], true)) {
    fwrite(STDERR, "PERF_SCENARIO precisa ser pequeno ou ampliado.\n");
    exit(2);
}
if (! app()->environment('e2e') || DB::scalar('select current_database()') !== 'cs_tactical_e2e') {
    fwrite(STDERR, "Recusado: só roda com APP_ENV=e2e no banco cs_tactical_e2e.\n");
    exit(2);
}

/** @return array<int, User> */
function perfUsers(): array
{
    $users = [];
    for ($n = 1; $n <= 6; $n++) {
        $email = "perf-{$n}@e2e.test";
        $user = User::whereEmailIs($email)->first();
        if ($user === null) {
            $user = new User;
            $user->forceFill([
                'name' => "Conta perf {$n}",
                'email' => $email,
                'password' => PrepareE2eDatabaseCommand::PASSWORD,
                'email_verified_at' => now(),
            ])->save();
            $user->profile()->create(['nickname' => "e2e_perf_{$n}", 'country_code' => 'BR', 'timezone' => 'America/Sao_Paulo']);
        }
        $users[$n] = $user;
    }

    return $users;
}

/** Respostas a partir do snapshot da tentativa: todas certas ou todas erradas. */
function answers(array $snapshot, bool $right): array
{
    return array_map(function (array $question) use ($right) {
        $correct = array_values(array_filter($question['options'], fn (array $o) => $o['correct']));
        $wrong = array_values(array_filter($question['options'], fn (array $o) => ! $o['correct']));
        $pick = $right ? $correct : [$wrong[0]];

        return ['question_id' => $question['id'], 'option_ids' => array_map(fn (array $o) => $o['id'], $pick)];
    }, $snapshot['questions']);
}

function attempt(User $user, string $quiz, bool $right): void
{
    $started = app(StartQuizAttempt::class)->handle($user, $quiz);
    if ($started === null) {
        throw new RuntimeException("quiz {$quiz} não é público");
    }
    [$attempt] = $started;
    app(SubmitQuizAttempt::class)->handle($user, (string) $attempt->id, answers($attempt->snapshot, $right));
}

function complete(User $user, string $course, string $lesson): void
{
    if (app(CompleteLesson::class)->handle($user, $course, $lesson) === null) {
        throw new RuntimeException("aula {$course}/{$lesson} não é pública");
    }
}

$users = perfUsers();
$player = $users[1];
$started = microtime(true);

if ($scenario === 'pequeno') {
    app(EnrollInCourse::class)->handle($player, 'fundamentos-e2e');
    complete($player, 'fundamentos-e2e', 'counter-strafe');
    attempt($player, 'counter-strafe-e2e', false);
} else {
    for ($c = 1; $c <= 20; $c++) {
        $course = sprintf('perf-curso-%02d', $c);
        app(EnrollInCourse::class)->handle($player, $course);

        $done = 0;
        for ($m = 1; $m <= 5 && $done < 2 * $c; $m++) {
            for ($l = 1; $l <= 8 && $done < 2 * $c; $l++, $done++) {
                complete($player, $course, "m{$m}-aula-{$l}");
            }
        }

        if ($c <= 10) {
            for ($m = 1; $m <= 5; $m++) {
                attempt($player, "{$course}-m{$m}-quiz", false);
                if ($m % 2 === 1) {
                    attempt($player, "{$course}-m{$m}-quiz", true);
                }
            }
        }
    }
}

$counts = DB::table('enrollments')->where('user_id', $player->getKey())->count().' matrículas, '
    .DB::table('lesson_progress')->where('user_id', $player->getKey())->where('status', 'completed')->count().' aulas concluídas, '
    .DB::table('quiz_attempts')->where('user_id', $player->getKey())->count().' tentativas';
printf("Jogadores de medição prontos (%s): perf-1 com %s; perf-2..perf-6 sem histórico (%.1f s).\n", $scenario, $counts, microtime(true) - $started);
