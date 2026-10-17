<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
| Rebaixamento simultâneo dos dois últimos admins (ADR 0012, etapa 1.8).
|
| Fora da suíte padrão: rode com `make test-concurrency` (docs/development/fase-1-auditoria.md).
|
| - Banco próprio e descartável, criado e apagado pelo teste: o banco de testes comum não é usado.
| - Dois `identity:set-role` em processos PHP independentes, cada um com a sua conexão.
| - Sincronização determinística: uma terceira conexão segura a trava das linhas de admin; o teste
|   só a solta quando o PostgreSQL mostra os DOIS processos esperando por ela.
| - Tempo máximo em cada espera e limpeza garantida (processos, conexões e banco).
*/

uses(TestCase::class)->group('concurrency');

const CONCURRENCY_TIMEOUT_SECONDS = 20;

function waitUntil(callable $condition, string $what): void
{
    $deadline = microtime(true) + CONCURRENCY_TIMEOUT_SECONDS;
    while (! $condition()) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException("Tempo esgotado esperando: {$what}");
        }
        usleep(50_000); // intervalo de consulta; a condição é que decide quando seguir
    }
}

it('keeps one admin when the last two are demoted at the same time', function () {
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

        foreach (['first-admin@example.com', 'second-admin@example.com'] as $email) {
            $admin = new User;
            $admin->setConnection('concurrency');
            $admin->forceFill([
                'name' => $email,
                'email' => $email,
                'password' => 'secret-password-1',
                'email_verified_at' => now(),
                'platform_role' => PlatformRole::Admin,
            ])->save();
        }

        // A mesma trava que ChangePlatformRole pede, segura por outra conexão.
        $lock = DB::connection('concurrency_lock');
        $lock->beginTransaction();
        $lock->select('select id from users where platform_role = ? for update', [PlatformRole::Admin->value]);

        foreach (['first-admin@example.com', 'second-admin@example.com'] as $email) {
            $process = new Process(
                [PHP_BINARY, base_path('artisan'), 'identity:set-role', $email, 'user', '--force'],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'LOG_CHANNEL' => 'null'],
                null,
                CONCURRENCY_TIMEOUT_SECONDS,
            );
            $process->start();
            $processes[] = $process;
        }

        // Os dois processos precisam estar parados na trava antes de soltá-la.
        $waitingOnLock = fn (): int => (int) DB::connection('pgsql')->scalar(
            "select count(*) from pg_stat_activity where datname = ? and wait_event_type = 'Lock'",
            [$database],
        );
        waitUntil(fn () => $waitingOnLock() === 2, 'os dois processos bloqueados na trava dos admins');

        $lock->rollBack();

        foreach ($processes as $process) {
            $process->wait();
        }

        $succeeded = array_filter($processes, fn (Process $process) => $process->isSuccessful());
        $refused = array_filter($processes, fn (Process $process) => ! $process->isSuccessful());

        expect($succeeded)->toHaveCount(1)
            ->and($refused)->toHaveCount(1)
            ->and(array_values($refused)[0]->getOutput().array_values($refused)[0]->getErrorOutput())
            ->toContain('último admin')
            ->and(DB::connection('concurrency')->table('users')->where('platform_role', PlatformRole::Admin->value)->count())
            ->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }
        DB::purge('concurrency');
        DB::purge('concurrency_lock');
        DB::statement("DROP DATABASE IF EXISTS \"{$database}\" WITH (FORCE)");
    }
})->group('concurrency');
