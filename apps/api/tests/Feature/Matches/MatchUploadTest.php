<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserSteamAccount;
use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Jobs\ExpireMatchUpload;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\DemoSignature;
use App\Modules\Matches\Uploads\UploadStorage;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrontendSession;

/*
| Envio de demos em partes (etapa M3, docs/training/mvp-m3-envio-de-demos.md). Partes de 1 KiB e
| arquivos sintéticos (assinatura PBDEMS2 + bytes aleatórios): nenhuma demo real no repositório.
*/

const UPLOAD_PART = 1024;

beforeEach(function () {
    Storage::fake('matches-uploads');
    config(['matches.part_size_bytes' => UPLOAD_PART, 'matches.disk_reserve_bytes' => 0]);
    $this->user = uploader();
});

function uploader(): User
{
    $user = User::factory()->withProfile()->create();
    UserSteamAccount::factory()->for($user)->create();

    return $user;
}

/** Arquivo sintético com a assinatura de demo do Source 2. */
function demoBytes(int $size): string
{
    return DemoSignature::MAGIC.random_bytes($size - strlen(DemoSignature::MAGIC));
}

function startUpload(int $size, string $name = 'partida.dem'): TestResponse
{
    return test()->postJson('/api/v1/matches/uploads', ['name' => $name, 'size' => $size]);
}

function putPart(string $id, int $number, string $bytes, ?string $sha = null, ?int $length = null): TestResponse
{
    return test()->call('PUT', "/api/v1/matches/uploads/{$id}/parts/{$number}", server: [
        'CONTENT_TYPE' => 'application/octet-stream',
        'CONTENT_LENGTH' => (string) ($length ?? strlen($bytes)),
        'HTTP_X_CONTENT_SHA256' => $sha ?? hash('sha256', $bytes),
        'HTTP_ACCEPT' => 'application/json',
    ], content: $bytes);
}

/** Envia todas as partes de `$bytes` e devolve o id do envio. */
function uploadParts(string $bytes): string
{
    $id = startUpload(strlen($bytes))->assertCreated()->json('upload.id');
    foreach (str_split($bytes, UPLOAD_PART) as $index => $part) {
        putPart($id, $index + 1, $part)->assertOk();
    }

    return $id;
}

function completeUpload(string $id): TestResponse
{
    return test()->postJson("/api/v1/matches/uploads/{$id}/complete");
}

function uploadFiles(): array
{
    return Storage::disk('matches-uploads')->allFiles();
}

describe('start (I1 to I6)', function () {
    it('requires a session and a CSRF token', function () {
        (new FrontendSession(test()))->post('/api/v1/matches/uploads', ['name' => 'a.dem', 'size' => 10])->assertUnauthorized();

        $user = User::factory()->withProfile()->create(['email' => 'csrf@example.com', 'password' => 'password-1']);
        UserSteamAccount::factory()->for($user)->create();
        $session = (new FrontendSession(test()))->realCsrf();
        $session->csrfCookie();
        $session->post('/api/v1/auth/login', ['email' => 'csrf@example.com', 'password' => 'password-1'])->assertOk();
        $session->withoutXsrfHeader()->post('/api/v1/matches/uploads', ['name' => 'a.dem', 'size' => 10])->assertStatus(419);

        expect(MatchUpload::query()->count())->toBe(0);
    });

    it('requires a linked Steam account', function () {
        $this->actingAs(User::factory()->create());

        startUpload(3000)->assertStatus(409)->assertJsonPath('code', 'steam_link_required');
    });

    it('validates the name and the size', function (array $body) {
        $this->actingAs($this->user);

        $this->postJson('/api/v1/matches/uploads', $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect(MatchUpload::query()->count())->toBe(0);
    })->with([
        'tamanho zero' => [['name' => 'a.dem', 'size' => 0]],
        'negativo' => [['name' => 'a.dem', 'size' => -1]],
        'acima de 600 MiB' => [['name' => 'a.dem', 'size' => 600 * 1024 * 1024 + 1]],
        'não inteiro' => [['name' => 'a.dem', 'size' => 10.5]],
        'nome sem .dem' => [['name' => 'partida.zip', 'size' => 10]],
        'nome longo' => [['name' => str_repeat('a', 252).'.dem', 'size' => 10]],
        'lista no lugar do nome' => [['name' => ['a.dem'], 'size' => 10]],
    ]);

    it('creates the upload with the server part size and schedules its expiry', function () {
        Queue::fake();
        $this->freezeTime();
        $this->actingAs($this->user);

        $response = startUpload(2500, '../../etc/passwd.dem')->assertCreated();

        expect($response->json('upload'))->toMatchArray([
            'status' => 'uploading',
            'name' => '../../etc/passwd.dem',
            'size_bytes' => 2500,
            'part_size' => UPLOAD_PART,
            'parts_total' => 3,
            'parts_received' => 0,
            'expires_at' => now()->addMinutes(120)->toIso8601ZuluString(),
        ])->and($response->json('upload'))->not->toHaveKey('sha256');
        Queue::assertPushed(ExpireMatchUpload::class, fn (ExpireMatchUpload $job) => $job->uploadId === $response->json('upload.id'));
        expect(uploadFiles())->toBe([]);
    });

    it('allows one open upload per user', function () {
        $this->actingAs($this->user);
        startUpload(3000)->assertCreated();

        startUpload(3000)->assertStatus(409)->assertJsonPath('code', 'match_upload_already_open');
    });

    it('limits completed uploads to five in 24 hours, without counting cancelled or rejected', function () {
        $this->freezeTime();
        MatchUpload::factory()->for($this->user)->completed()->count(4)->create(['completed_at' => now()->subHours(2)]);
        MatchUpload::factory()->for($this->user)->count(3)->create(['status' => MatchUploadStatus::Cancelled]);
        $this->actingAs($this->user);

        startUpload(3000)->assertCreated();
        MatchUpload::query()->where('status', 'uploading')->update(['status' => 'cancelled']);
        MatchUpload::factory()->for($this->user)->completed()->create(['completed_at' => now()->subHours(23)]);

        startUpload(3000)->assertStatus(429)
            ->assertJsonPath('code', 'match_upload_quota_exceeded')
            ->assertHeader('Retry-After', (string) 3600);
    });

    it('refuses when the disk would run out of space', function () {
        config(['matches.disk_reserve_bytes' => PHP_INT_MAX - 10_000]);
        $this->actingAs($this->user);

        startUpload(3000)->assertStatus(503)->assertJsonPath('code', 'match_upload_storage_unavailable');
        expect(MatchUpload::query()->count())->toBe(0);
    });
});

describe('parts (P1 to P5)', function () {
    beforeEach(function () {
        $this->actingAs($this->user);
        $this->bytes = demoBytes(2500);
        $this->id = startUpload(2500)->json('upload.id');
        [$this->p1, $this->p2, $this->p3] = str_split($this->bytes, UPLOAD_PART);
    });

    it('writes each part at its offset, in order', function () {
        putPart($this->id, 1, $this->p1)->assertOk()->assertJsonPath('upload.parts_received', 1);
        putPart($this->id, 2, $this->p2)->assertOk();
        putPart($this->id, 3, $this->p3)->assertOk()->assertJsonPath('upload.parts_received', 3);

        expect(Storage::disk('matches-uploads')->get("uploads/{$this->id}.part"))->toBe($this->bytes);
    });

    it('refuses parts out of order', function () {
        putPart($this->id, 2, $this->p2)->assertStatus(409)->assertJsonPath('code', 'match_upload_part_out_of_order');
        putPart($this->id, 1, $this->p1)->assertOk();
        putPart($this->id, 3, $this->p3)->assertStatus(409)->assertJsonPath('code', 'match_upload_part_out_of_order');
    });

    it('accepts the last part again with the same hash, without rewriting', function () {
        putPart($this->id, 1, $this->p1)->assertOk();
        $before = Storage::disk('matches-uploads')->lastModified("uploads/{$this->id}.part");

        putPart($this->id, 1, $this->p1)->assertOk()->assertJsonPath('upload.parts_received', 1);
        putPart($this->id, 1, $this->p2)->assertStatus(409)->assertJsonPath('code', 'match_upload_part_out_of_order');

        expect(Storage::disk('matches-uploads')->get("uploads/{$this->id}.part"))->toBe($this->p1)
            ->and(Storage::disk('matches-uploads')->lastModified("uploads/{$this->id}.part"))->toBe($before);
    });

    it('checks the declared size before reading the body', function () {
        putPart($this->id, 1, $this->p1, length: UPLOAD_PART + 1)->assertStatus(422)->assertJsonPath('code', 'match_upload_part_size_invalid');
        putPart($this->id, 1, substr($this->p1, 1))->assertStatus(422)->assertJsonPath('code', 'match_upload_part_size_invalid');
        putPart($this->id, 1, $this->p1)->assertOk();
        putPart($this->id, 2, $this->p2)->assertOk();
        // A última parte leva o resto exato (2500 − 2048 = 452).
        putPart($this->id, 3, $this->p3.'x')->assertStatus(422)->assertJsonPath('code', 'match_upload_part_size_invalid');
        // Corpo menor que o declarado.
        putPart($this->id, 3, substr($this->p3, 1), length: 452)->assertStatus(422)->assertJsonPath('code', 'match_upload_part_size_invalid');
    });

    it('refuses a part that does not match its hash', function (string $sha) {
        putPart($this->id, 1, $this->p1, sha: $sha)->assertStatus(422)->assertJsonPath('code', 'match_upload_part_corrupted');

        expect(MatchUpload::query()->value('parts_received'))->toBe(0)
            ->and(uploadFiles())->toBe([]);
    })->with([
        'hash de outra parte' => fn () => hash('sha256', 'outra'),
        'sem hash' => '',
        'não hexadecimal' => str_repeat('z', 64),
    ]);

    it('answers 404 for an upload of another user or that does not exist', function () {
        $this->actingAs(uploader());

        putPart($this->id, 1, $this->p1)->assertNotFound();
        $this->getJson("/api/v1/matches/uploads/{$this->id}")->assertNotFound();
        completeUpload($this->id)->assertNotFound();
        $this->deleteJson("/api/v1/matches/uploads/{$this->id}")->assertNotFound();
        putPart('00000000-0000-7000-8000-000000000000', 1, $this->p1)->assertNotFound();
        $this->getJson('/api/v1/matches/uploads/nao-e-uuid')->assertNotFound();

        expect(MatchUpload::query()->value('parts_received'))->toBe(0);
    });

    it('refuses parts after the upload is closed or expired', function () {
        $this->deleteJson("/api/v1/matches/uploads/{$this->id}")->assertNoContent();
        putPart($this->id, 1, $this->p1)->assertStatus(409)->assertJsonPath('code', 'match_upload_not_open');

        $id = startUpload(2500)->json('upload.id');
        $this->travel(121)->minutes();
        putPart($id, 1, $this->p1)->assertStatus(409)->assertJsonPath('code', 'match_upload_not_open');
    });
});

describe('completion (C1 to C6)', function () {
    beforeEach(fn () => $this->actingAs($this->user));

    it('completes a demo: renamed, hashed and expiring in 24 hours', function () {
        $this->freezeTime();
        $bytes = demoBytes(2500);
        $id = uploadParts($bytes);

        completeUpload($id)->assertOk()
            ->assertJsonPath('upload.status', 'completed')
            ->assertJsonPath('upload.expires_at', now()->addHours(24)->toIso8601ZuluString());

        expect(uploadFiles())->toBe(["uploads/{$id}.dem"])
            ->and(Storage::disk('matches-uploads')->get("uploads/{$id}.dem"))->toBe($bytes)
            ->and(MatchUpload::query()->value('sha256'))->toBe(hash('sha256', $bytes));
    });

    it('refuses to complete before all parts arrive', function () {
        $bytes = demoBytes(2500);
        $id = startUpload(2500)->json('upload.id');
        putPart($id, 1, substr($bytes, 0, UPLOAD_PART))->assertOk();

        completeUpload($id)->assertStatus(409)->assertJsonPath('code', 'match_upload_incomplete');
        expect(MatchUpload::query()->value('status'))->toBe(MatchUploadStatus::Uploading);
    });

    it('rejects a file that is not a demo and deletes it', function (string $bytes) {
        $id = uploadParts($bytes);

        completeUpload($id)->assertStatus(422)->assertJsonPath('code', 'match_upload_not_a_demo');

        expect(uploadFiles())->toBe([])
            ->and(MatchUpload::query()->value('status'))->toBe(MatchUploadStatus::Rejected);
        $this->getJson("/api/v1/matches/uploads/{$id}")->assertJsonPath('upload.rejection_code', 'not_a_demo');
        startUpload(10)->assertCreated(); // o recusado não ocupa o envio aberto
    })->with([
        'texto renomeado' => fn () => str_repeat('não é uma demo ', 100),
        'ZIP' => fn () => "PK\x03\x04".random_bytes(1496),
        'assinatura do Source 1' => fn () => "HL2DEMO\0".random_bytes(1492),
        'assinatura no meio' => fn () => random_bytes(16).DemoSignature::MAGIC.random_bytes(1476),
    ]);

    it('rejects when the assembled file has another size', function () {
        $id = uploadParts(demoBytes(2500));
        Storage::disk('matches-uploads')->append("uploads/{$id}.part", 'x', '');

        completeUpload($id)->assertStatus(422)->assertJsonPath('code', 'match_upload_size_mismatch');
        expect(uploadFiles())->toBe([]);
    });

    it('refuses the same demo twice for the same user, but not for another player', function () {
        $bytes = demoBytes(2500);
        completeUpload(uploadParts($bytes))->assertOk();

        $again = uploadParts($bytes);
        completeUpload($again)->assertStatus(409)->assertJsonPath('code', 'match_upload_duplicate');
        expect(Storage::disk('matches-uploads')->exists("uploads/{$again}.part"))->toBeFalse();

        $this->actingAs(uploader());
        completeUpload(uploadParts($bytes))->assertOk();
    });

    it('refuses to complete when the Steam account was unlinked meanwhile', function () {
        $id = uploadParts(demoBytes(2500));
        $this->user->steamAccount()->delete();

        completeUpload($id)->assertStatus(409)->assertJsonPath('code', 'steam_link_required');
        expect(MatchUpload::query()->value('status'))->toBe(MatchUploadStatus::Uploading);
    });

    it('does not complete twice', function () {
        $id = uploadParts(demoBytes(2500));
        completeUpload($id)->assertOk();

        completeUpload($id)->assertStatus(409)->assertJsonPath('code', 'match_upload_not_open');
    });
});

describe('cancellation and expiry', function () {
    beforeEach(fn () => $this->actingAs($this->user));

    it('cancels an open or completed upload and deletes the file', function () {
        $open = startUpload(2500)->json('upload.id');
        putPart($open, 1, substr(demoBytes(2500), 0, UPLOAD_PART))->assertOk();
        $this->deleteJson("/api/v1/matches/uploads/{$open}")->assertNoContent();

        $done = uploadParts(demoBytes(2500));
        completeUpload($done)->assertOk();
        $this->deleteJson("/api/v1/matches/uploads/{$done}")->assertNoContent();
        $this->deleteJson("/api/v1/matches/uploads/{$done}")->assertStatus(409)->assertJsonPath('code', 'match_upload_not_open');

        expect(uploadFiles())->toBe([])
            ->and(MatchUpload::query()->pluck('status')->unique()->all())->toBe([MatchUploadStatus::Cancelled]);
    });

    it('expires an idle upload after 2 hours and a completed one after 24 hours', function () {
        $open = startUpload(2500)->json('upload.id');
        putPart($open, 1, substr(demoBytes(2500), 0, UPLOAD_PART))->assertOk();

        $this->travel(119)->minutes();
        (new ExpireMatchUpload($open))->handle(app(UploadStorage::class));
        expect(MatchUpload::query()->find($open)->status)->toBe(MatchUploadStatus::Uploading);

        $this->travel(2)->minutes();
        (new ExpireMatchUpload($open))->handle(app(UploadStorage::class));
        expect(MatchUpload::query()->find($open)->status)->toBe(MatchUploadStatus::Expired)
            ->and(uploadFiles())->toBe([]);

        $done = uploadParts(demoBytes(2500));
        completeUpload($done)->assertOk();
        $this->travel(25)->hours();
        (new ExpireMatchUpload($done))->handle(app(UploadStorage::class));
        expect(MatchUpload::query()->find($done)->status)->toBe(MatchUploadStatus::Expired)
            ->and(uploadFiles())->toBe([]);
    });

    it('ignores an old expiry job after new activity and a job for a closed upload', function () {
        $id = startUpload(2500)->json('upload.id');
        $bytes = demoBytes(2500);
        $this->travel(100)->minutes();
        putPart($id, 1, substr($bytes, 0, UPLOAD_PART))->assertOk(); // novo prazo: +120 min

        $this->travel(30)->minutes(); // o prazo do início (120 min) já passou; o novo, não
        (new ExpireMatchUpload($id))->handle(app(UploadStorage::class));
        expect(MatchUpload::query()->find($id)->status)->toBe(MatchUploadStatus::Uploading);

        $this->deleteJson("/api/v1/matches/uploads/{$id}")->assertNoContent();
        (new ExpireMatchUpload($id))->handle(app(UploadStorage::class));
        expect(MatchUpload::query()->find($id)->status)->toBe(MatchUploadStatus::Cancelled);
    });

    it('prunes overdue uploads and old orphan files, never a live one', function () {
        $disk = Storage::disk('matches-uploads');
        $live = startUpload(2500)->json('upload.id');
        putPart($live, 1, substr(demoBytes(2500), 0, UPLOAD_PART))->assertOk();
        $orphan = '01a1aaaa-0000-7000-8000-000000000001';
        $disk->put("uploads/{$orphan}.dem", 'x');
        $disk->put('uploads/notas.txt', 'x');

        $this->travel(25)->hours(); // o envio aberto venceu; os arquivos têm mais de 24 h
        touch($disk->path("uploads/{$orphan}.dem"), now()->subHours(25)->getTimestamp());
        touch($disk->path("uploads/{$live}.part"), now()->subHours(25)->getTimestamp());

        $this->artisan('matches:prune-uploads', ['--dry-run' => true])->assertSuccessful();
        expect($disk->exists("uploads/{$orphan}.dem"))->toBeTrue();

        $this->artisan('matches:prune-uploads')
            ->expectsOutputToContain('envios expirados: 1')
            ->expectsOutputToContain('fora do padrão')
            ->assertSuccessful();

        expect($disk->allFiles())->toBe(['uploads/notas.txt'])
            ->and(MatchUpload::query()->find($live)->status)->toBe(MatchUploadStatus::Expired);
    });

    it('keeps the file of a live upload even when it is old', function () {
        $disk = Storage::disk('matches-uploads');
        $id = startUpload(2500)->json('upload.id');
        putPart($id, 1, substr(demoBytes(2500), 0, UPLOAD_PART))->assertOk();
        touch($disk->path("uploads/{$id}.part"), now()->subHours(30)->getTimestamp());

        $this->artisan('matches:prune-uploads')->assertSuccessful();

        expect($disk->exists("uploads/{$id}.part"))->toBeTrue();
    });
});

describe('authorization', function () {
    it('protects every route with a session and a rate limit', function () {
        foreach (['store', 'show', 'parts.store', 'complete', 'destroy'] as $name) {
            $middleware = collect(Route::getRoutes()->getByName("v1.matches.uploads.{$name}")->gatherMiddleware());

            expect($middleware->contains('auth:sanctum'))->toBeTrue($name)
                ->and($middleware->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue($name);
        }
    });

    it('lets each user see and change only their own uploads, whatever the platform role', function (string $role) {
        $user = match ($role) {
            'user' => User::factory()->create(),
            'editor' => User::factory()->editor()->create(),
            'admin' => User::factory()->admin()->create(),
        };
        $own = MatchUpload::factory()->for($user)->create();
        $other = MatchUpload::factory()->create();

        expect(Gate::forUser($user)->allows('view', $own))->toBeTrue()
            ->and(Gate::forUser($user)->allows('update', $own))->toBeTrue()
            ->and(Gate::forUser($user)->allows('view', $other))->toBeFalse()
            ->and(Gate::forUser($user)->allows('update', $other))->toBeFalse();
    })->with(['user', 'editor', 'admin']);
});

describe('database', function () {
    it('enforces one open upload per user, one completed hash per user and the checks', function () {
        $user = User::factory()->create();
        MatchUpload::factory()->for($user)->create();
        MatchUpload::factory()->for($user)->completed(str_repeat('a', 64))->create();

        $insert = fn (array $overrides) => DB::transaction(fn () => MatchUpload::factory()->for($user)->create($overrides));

        expect(fn () => $insert([]))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => $insert(['status' => MatchUploadStatus::Completed, 'parts_received' => 3, 'sha256' => str_repeat('a', 64), 'completed_at' => now()]))
            ->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => $insert(['status' => MatchUploadStatus::Cancelled, 'size_bytes' => 629145601]))->toThrow(QueryException::class)
            ->and(fn () => $insert(['status' => MatchUploadStatus::Cancelled, 'parts_received' => 4]))->toThrow(QueryException::class)
            ->and(fn () => $insert(['status' => MatchUploadStatus::Rejected]))->toThrow(QueryException::class)
            ->and(fn () => $insert(['status' => MatchUploadStatus::Completed]))->toThrow(QueryException::class);
    });

    it('cascades from the user and rolls back cleanly', function () {
        $upload = MatchUpload::factory()->create();
        $upload->user->delete();
        expect(MatchUpload::query()->count())->toBe(0);

        $this->artisan('migrate:rollback', ['--step' => migrationsFrom('2026_10_25_100000_create_match_uploads_table')])->assertSuccessful();
        expect(Schema::hasTable('match_uploads'))->toBeFalse();
        $this->artisan('migrate')->assertSuccessful();
        expect(Schema::hasTable('match_uploads'))->toBeTrue();
    });
});
