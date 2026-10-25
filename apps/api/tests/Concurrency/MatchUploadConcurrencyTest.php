<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserSteamAccount;
use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\DemoSignature;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/*
| Envio de demos com disputa real (etapa M3, docs/training/mvp-m3-envio-de-demos.md).
|
| Fora da suíte padrão: `make test-concurrency`. Mesmo método dos outros testes de concorrência:
| banco e pasta de arquivos descartáveis, dois processos PHP chamando a ação do Matches, e uma
| terceira conexão segurando o ponto de disputa (a linha do envio, ou o índice único) até os DOIS
| processos estarem esperando.
*/

uses(TestCase::class)->group('concurrency');

const MATCHES_CONCURRENCY_TIMEOUT = 20;

/**
 * @param  Closure(Connection $db, string $root): array{0: string, 1: string}  $prepare  devolve [userId, uploadId]
 * @param  Closure(Connection $lock, string $userId, string $uploadId): void  $hold  trava o ponto de disputa
 * @param  Closure(string $userId, string $uploadId, int $process): string  $code  código do `tinker --execute`
 * @param  Closure(list<string> $outputs, Connection $db, string $root, string $uploadId): void  $assert
 */
function matchesRace(Closure $prepare, Closure $hold, Closure $code, Closure $assert): void
{
    $database = 'cs_tactical_concurrency_'.Str::lower(Str::random(8));
    $root = sys_get_temp_dir().'/'.$database;
    $base = config('database.connections.pgsql');
    expect($base['database'])->toBe('cs_tactical_test'); // nunca a partir do banco de desenvolvimento

    DB::statement("CREATE DATABASE \"{$database}\"");
    File::ensureDirectoryExists($root.'/uploads');
    config([
        'database.connections.concurrency' => [...$base, 'database' => $database],
        'database.connections.concurrency_lock' => [...$base, 'database' => $database],
    ]);
    $processes = [];

    try {
        Artisan::call('migrate', ['--database' => 'concurrency', '--force' => true]);

        DB::setDefaultConnection('concurrency');
        [$userId, $uploadId] = $prepare(DB::connection('concurrency'), $root);
        DB::setDefaultConnection('pgsql');

        $lock = DB::connection('concurrency_lock');
        $lock->beginTransaction();
        $hold($lock, $userId, $uploadId);

        foreach ([1, 2] as $number) {
            $process = new Process(
                [PHP_BINARY, base_path('artisan'), 'tinker', '--execute', $code($userId, $uploadId, $number)],
                base_path(),
                [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => $database,
                    'LOG_CHANNEL' => 'null', 'QUEUE_CONNECTION' => 'sync', 'MATCHES_UPLOADS_ROOT' => $root,
                ],
                null,
                MATCHES_CONCURRENCY_TIMEOUT,
            );
            $process->start();
            $processes[] = $process;
        }

        $waiting = fn (): int => (int) DB::connection('pgsql')->scalar(
            "select count(*) from pg_stat_activity where datname = ? and wait_event_type = 'Lock'",
            [$database],
        );
        $deadline = microtime(true) + MATCHES_CONCURRENCY_TIMEOUT;
        while ($waiting() !== 2) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Tempo esgotado esperando os dois processos no ponto de disputa');
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
        $assert($outputs, DB::connection('concurrency'), $root, $uploadId);
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
        File::deleteDirectory($root);
    }
}

/** Jogador com conta Steam vinculada. */
function matchesPlayer(): User
{
    $user = User::factory()->create();
    UserSteamAccount::factory()->for($user)->create();

    return $user;
}

/** Envio aberto de `$size` bytes em partes de 1 KiB, com `$received` partes já recebidas. */
function matchesUpload(User $user, int $size, int $received): MatchUpload
{
    $upload = new MatchUpload;
    $upload->forceFill([
        'user_id' => $user->id, 'status' => MatchUploadStatus::Uploading, 'name' => 'partida.dem',
        'size_bytes' => $size, 'part_size' => 1024, 'parts_total' => (int) ceil($size / 1024),
        'parts_received' => $received, 'last_activity_at' => now(), 'expires_at' => now()->addHour(),
    ])->save();

    return $upload;
}

function matchesAction(string $class, string $call): string
{
    return "try { \$a = app(App\\Modules\\Matches\\Actions\\{$class}::class); {$call} echo 'OK'; } "
        .'catch (App\\Modules\\Matches\\Exceptions\\MatchUploadFailed $e) { echo $e->errorCode; }';
}

it('completes an upload once when it is completed twice at the same time', function () {
    $bytes = DemoSignature::MAGIC.str_repeat('x', 2492);

    matchesRace(
        function ($db, string $root) use ($bytes) {
            $upload = matchesUpload(matchesPlayer(), strlen($bytes), 3);
            file_put_contents("{$root}/uploads/{$upload->id}.part", $bytes);

            return [$upload->user_id, $upload->id];
        },
        fn ($lock, $userId, $uploadId) => $lock->select('select id from match_uploads where id = ? for update', [$uploadId]),
        fn ($userId, $uploadId) => matchesAction('CompleteMatchUpload', "\$a->handle(App\\Modules\\Matches\\Models\\MatchUpload::findOrFail('{$uploadId}'));"),
        function (array $outputs, $db, string $root, string $uploadId) use ($bytes) {
            expect($outputs)->toBe(['OK', 'match_upload_not_open'])
                ->and($db->table('match_uploads')->where('id', $uploadId)->value('status'))->toBe('completed')
                ->and($db->table('match_uploads')->where('id', $uploadId)->value('sha256'))->toBe(hash('sha256', $bytes))
                ->and(file_get_contents("{$root}/uploads/{$uploadId}.dem"))->toBe($bytes)
                ->and(file_exists("{$root}/uploads/{$uploadId}.part"))->toBeFalse();
        },
    );
});

it('opens a single upload when the same player starts two at the same time', function () {
    matchesRace(
        fn () => [matchesPlayer()->id, ''],
        // Um envio aberto do mesmo jogador, ainda não confirmado: os dois INSERT param no único parcial.
        fn ($lock, $userId) => $lock->table('match_uploads')->insert([
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'status' => 'uploading', 'name' => 'a.dem',
            'size_bytes' => 10, 'part_size' => 1024, 'parts_total' => 1, 'parts_received' => 0,
            'last_activity_at' => now(), 'expires_at' => now()->addHour(),
        ]),
        fn ($userId) => matchesAction('StartMatchUpload', "\$a->handle(App\\Modules\\Identity\\Models\\User::findOrFail('{$userId}'), 'partida.dem', 3000);"),
        function (array $outputs, $db) {
            expect($outputs)->toBe(['OK', 'match_upload_already_open'])
                ->and($db->table('match_uploads')->where('status', 'uploading')->count())->toBe(1);
        },
    );
});

it('writes one part when the same part number arrives twice at the same time', function () {
    matchesRace(
        fn () => [($upload = matchesUpload(matchesPlayer(), 2500, 0))->user_id, $upload->id],
        fn ($lock, $userId, $uploadId) => $lock->select('select id from match_uploads where id = ? for update', [$uploadId]),
        // Conteúdos diferentes: se as duas escritas se misturassem, o arquivo mostraria.
        function ($userId, $uploadId, int $process) {
            $byte = $process === 1 ? 'a' : 'b';

            return matchesAction(
                'StoreMatchUploadPart',
                "\$b = str_repeat('{$byte}', 1024); \$a->handle(App\\Modules\\Matches\\Models\\MatchUpload::findOrFail('{$uploadId}'), 1, 1024, hash('sha256', \$b), fn () => \$b);",
            );
        },
        function (array $outputs, $db, string $root, string $uploadId) {
            $content = file_get_contents("{$root}/uploads/{$uploadId}.part");

            expect($outputs)->toBe(['OK', 'match_upload_part_out_of_order'])
                ->and($db->table('match_uploads')->where('id', $uploadId)->value('parts_received'))->toBe(1)
                ->and(in_array($content, [str_repeat('a', 1024), str_repeat('b', 1024)], true))->toBeTrue()
                ->and($db->table('match_uploads')->where('id', $uploadId)->value('last_part_sha256'))->toBe(hash('sha256', $content));
        },
    );
});
