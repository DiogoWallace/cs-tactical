<?php

use App\Modules\Identity\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\LearningFixtures;
use Tests\TestCase;

/*
| Matrícula e conclusão simultâneas do mesmo jogador (etapa 2.4, ADR 0017).
|
| Fora da suíte padrão: `make test-concurrency`. Mesmo método do teste do último admin:
| - banco próprio e descartável, criado e apagado pelo teste;
| - dois processos PHP independentes chamando a ação do Learning, cada um com a sua conexão;
| - sincronização determinística: uma terceira conexão trava a linha do curso (FOR UPDATE); as
|   ações pedem FOR SHARE nela e param. O teste só solta quando os DOIS processos estão
|   esperando, e aí eles seguem juntos até o INSERT, onde o único (user_id, course_id) e o
|   (user_id, lesson_id) decidem.
*/

uses(TestCase::class)->group('concurrency');

const LEARNING_CONCURRENCY_TIMEOUT = 20;

function learningWaitUntil(callable $condition, string $what): void
{
    $deadline = microtime(true) + LEARNING_CONCURRENCY_TIMEOUT;
    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Tempo esgotado esperando: {$what}");
        }
        usleep(50_000);
    }
}

/**
 * Prepara o banco descartável com um jogador e o curso público de LearningFixtures, roda o código
 * PHP em dois processos ao mesmo tempo e devolve [processos, conexão do banco]. Limpa tudo no fim.
 *
 * @param  Closure(string $userId): string  $code  código para o `tinker --execute`
 * @param  Closure(list<Process>, Connection): void  $assert
 */
function learningRace(Closure $code, Closure $assert): void
{
    $database = 'cs_tactical_concurrency_'.Str::lower(Str::random(8));
    $base = config('database.connections.pgsql');
    expect($base['database'])->toBe('cs_tactical_test'); // nunca a partir do banco de desenvolvimento

    DB::statement("CREATE DATABASE \"{$database}\"");
    config([
        'database.connections.concurrency' => [...$base, 'database' => $database],
        'database.connections.concurrency_lock' => [...$base, 'database' => $database],
    ]);
    $processes = [];

    try {
        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);

        DB::setDefaultConnection('concurrency');
        [$course] = LearningFixtures::course();
        $user = User::factory()->create();
        DB::setDefaultConnection('pgsql');

        $lock = DB::connection('concurrency_lock');
        $lock->beginTransaction();
        $lock->select('select id from courses where id = ? for update', [$course->id]);

        foreach ([1, 2] as $_) {
            $process = new Process(
                [PHP_BINARY, base_path('artisan'), 'tinker', '--execute', $code($user->id)],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'LOG_CHANNEL' => 'null'],
                null,
                LEARNING_CONCURRENCY_TIMEOUT,
            );
            $process->start();
            $processes[] = $process;
        }

        $waiting = fn (): int => (int) DB::connection('pgsql')->scalar(
            "select count(*) from pg_stat_activity where datname = ? and wait_event_type = 'Lock'",
            [$database],
        );
        learningWaitUntil(fn () => $waiting() === 2, 'os dois processos bloqueados na trava do curso');

        $lock->rollBack();
        foreach ($processes as $process) {
            $process->wait();
        }

        $assert($processes, DB::connection('concurrency'));
    } finally {
        DB::setDefaultConnection('pgsql');
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        DB::purge('concurrency');
        DB::purge('concurrency_lock');
        DB::statement("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
    }
}

it('creates a single enrollment when the same player enrolls twice at the same time', function () {
    learningRace(
        fn (string $userId) => "\$r = app(App\\Modules\\Learning\\Actions\\EnrollInCourse::class)->handle(App\\Modules\\Identity\\Models\\User::findOrFail('{$userId}'), 'fundamentos'); echo \$r[1] ? 'CREATED' : 'EXISTING';",
        function (array $processes, $db) {
            foreach ($processes as $process) {
                expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            }
            $outputs = array_map(fn (Process $process) => trim($process->getOutput()), $processes);
            sort($outputs);

            expect($outputs)->toBe(['CREATED', 'EXISTING'])
                ->and($db->table('enrollments')->count())->toBe(1);
        },
    );
})->group('concurrency');

it('records a single completion (and enrollment) when the same lesson is completed twice at the same time', function () {
    learningRace(
        fn (string $userId) => "\$p = app(App\\Modules\\Learning\\Actions\\CompleteLesson::class)->handle(App\\Modules\\Identity\\Models\\User::findOrFail('{$userId}'), 'fundamentos', 'peek'); echo 'DONE '.\$p->completedLessons;",
        function (array $processes, $db) {
            foreach ($processes as $process) {
                expect($process->isSuccessful())->toBeTrue($process->getErrorOutput())
                    ->and(trim($process->getOutput()))->toBe('DONE 1');
            }

            expect($db->table('lesson_progress')->count())->toBe(1)
                ->and($db->table('enrollments')->count())->toBe(1)
                ->and($db->table('lesson_progress')->value('status'))->toBe('completed');
        },
    );
})->group('concurrency');
