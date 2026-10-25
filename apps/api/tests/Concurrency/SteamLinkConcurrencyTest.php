<?php

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
| Dois usuários concluindo o vínculo do mesmo SteamID ao mesmo tempo (etapa M2, regra 10).
|
| Fora da suíte padrão: `make test-concurrency`. Mesmo método dos outros testes de concorrência:
| - banco próprio e descartável, criado e apagado pelo teste;
| - dois processos PHP independentes chamando CompleteSteamLink::link(), cada um com a sua conexão;
| - sincronização determinística: uma terceira conexão insere o mesmo SteamID numa transação aberta;
|   os dois processos param no índice único. O teste só a desfaz quando os DOIS estão esperando, e
|   aí eles disputam o mesmo índice: um grava, o outro recebe `steam_account_in_use`.
*/

uses(TestCase::class)->group('concurrency');

const STEAM_CONCURRENCY_TIMEOUT = 20;

it('links a SteamID to only one of two users completing at the same time', function () {
    $database = 'cs_tactical_concurrency_'.Str::lower(Str::random(8));
    $base = config('database.connections.pgsql');
    expect($base['database'])->toBe('cs_tactical_test'); // nunca a partir do banco de desenvolvimento

    DB::statement("CREATE DATABASE \"{$database}\"");
    config([
        'database.connections.concurrency' => [...$base, 'database' => $database],
        'database.connections.concurrency_lock' => [...$base, 'database' => $database],
    ]);
    $processes = [];
    $steamId = '76561198000000001';

    try {
        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);

        DB::setDefaultConnection('concurrency');
        $users = User::factory()->count(3)->create();
        DB::setDefaultConnection('pgsql');

        $lock = DB::connection('concurrency_lock');
        $lock->beginTransaction();
        $lock->table('user_steam_accounts')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $users[2]->id, 'steam_id' => $steamId, 'linked_at' => now(),
        ]);

        foreach ([$users[0], $users[1]] as $user) {
            $code = "try { app(App\\Modules\\Identity\\Actions\\CompleteSteamLink::class)->link(App\\Modules\\Identity\\Models\\User::findOrFail('{$user->id}'), '{$steamId}'); echo 'LINKED'; } catch (App\\Modules\\Identity\\Steam\\SteamLinkFailed \$e) { echo \$e->errorCode; }";
            $process = new Process(
                [PHP_BINARY, base_path('artisan'), 'tinker', '--execute', $code],
                base_path(),
                ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database, 'LOG_CHANNEL' => 'null'],
                null,
                STEAM_CONCURRENCY_TIMEOUT,
            );
            $process->start();
            $processes[] = $process;
        }

        $waiting = fn (): int => (int) DB::connection('pgsql')->scalar(
            "select count(*) from pg_stat_activity where datname = ? and wait_event_type = 'Lock'",
            [$database],
        );
        $deadline = microtime(true) + STEAM_CONCURRENCY_TIMEOUT;
        while ($waiting() !== 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Tempo esgotado esperando os dois processos no índice único');
            }
            usleep(50_000);
        }

        $lock->rollBack();
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        }

        $outputs = array_map(fn (Process $process) => trim($process->getOutput()), $processes);
        sort($outputs);
        $db = DB::connection('concurrency');

        expect($outputs)->toBe(['LINKED', 'steam_account_in_use'])
            ->and($db->table('user_steam_accounts')->count())->toBe(1)
            ->and($db->table('user_steam_accounts')->value('steam_id'))->toBe($steamId);
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
});
