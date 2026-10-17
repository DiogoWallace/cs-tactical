<?php

use App\Modules\Academy\Content\CanonicalJson;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Import\MediaStore;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\AcademyContent;

/*
| GET /api/v1/academy/media/{sha256}.{ext} (ADR 0019; decisão da etapa 2.2): a imagem só é
| servida se for capa de curso público ou bloco `image` de aula pública; editores recebem
| também as de rascunho e arquivados. Nenhuma resposta vai para cache (`no-store`, sem ETag nem
| 304): cada requisição é autorizada de novo. O caminho lido do disco é o do registro.
*/

beforeEach(fn () => Storage::fake('academy'));
afterEach(fn () => AcademyContent::cleanup());

/** Grava um PNG no disco `academy` e registra em `academy_media`, como a importação. */
function mediaApiStored(int $seed = 1): Media
{
    $contents = AcademyContent::png(5, 3, $seed);
    $sha = hash('sha256', $contents);
    $path = MediaStore::pathFor($sha, 'png');
    Storage::disk('academy')->put($path, $contents);

    return Media::factory()->create([
        'sha256' => $sha, 'path' => $path, 'mime' => 'image/png', 'bytes' => strlen($contents), 'width' => 5, 'height' => 3,
    ]);
}

function mediaApiUrl(Media $media): string
{
    return '/api/v1/academy/media/'.basename($media->path);
}

/** Aula com um bloco `image` da mídia, com os estados pedidos de curso, módulo e aula. */
function mediaApiInLesson(Media $media, PublicationStatus $course, PublicationStatus $module, PublicationStatus $lesson): Lesson
{
    $content = ['version' => 1, 'blocks' => [
        ['type' => 'markdown', 'data' => ['text' => '## Aula']],
        ['type' => 'image', 'data' => ['media' => $media->sha256, 'alt' => 'Imagem', 'decorative' => false, 'caption' => null]],
    ]];
    $moduleModel = CourseModule::factory()->for(Course::factory()->status($course))->status($module)->create();

    return Lesson::factory()->forModule($moduleModel)->status($lesson)->create([
        'content' => $content,
        'content_hash' => CanonicalJson::hash($content),
    ]);
}

function mediaApiGet(?string $role, string $uri): TestResponse
{
    $user = match ($role) {
        null => null,
        'user' => User::factory()->create(),
        'editor' => User::factory()->editor()->create(),
        'admin' => User::factory()->admin()->create(),
    };
    if ($user !== null) {
        test()->actingAs($user);
    }

    return test()->get($uri);
}

/** @return array<string, array{PublicationStatus, PublicationStatus, PublicationStatus}> */
function mediaApiStates(): array
{
    $states = [PublicationStatus::Draft, PublicationStatus::Published, PublicationStatus::Archived];
    $matrix = [];
    foreach ($states as $course) {
        foreach ($states as $module) {
            foreach ($states as $lesson) {
                $matrix["curso {$course->value}, módulo {$module->value}, aula {$lesson->value}"] = [$course, $module, $lesson];
            }
        }
    }

    return $matrix;
}

/**
 * Nenhuma imagem pode ser guardada nem revalidada (decisão da revisão da 2.2): o acesso depende
 * do estado do conteúdo, que muda; cada requisição passa de novo pela autorização.
 */
function mediaApiAssertNotCacheable(TestResponse $response): void
{
    $cacheControl = (string) $response->headers->get('Cache-Control');

    expect($cacheControl)->toContain('no-store')
        ->not->toContain('public')
        ->not->toContain('max-age')
        ->and($response->headers->has('ETag'))->toBeFalse()
        ->and($response->headers->has('Last-Modified'))->toBeFalse()
        ->and($response->headers->has('Expires'))->toBeFalse();
}

it('serves the cover of a public course with its registered type and without cache', function () {
    $media = mediaApiStored();
    Course::factory()->published()->create(['cover_media_id' => $media->id]);

    $response = $this->get(mediaApiUrl($media))->assertOk();

    expect($response->streamedContent())->toBe(Storage::disk('academy')->get($media->path))
        ->and($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Content-Length'))->toBe((string) $media->bytes)
        ->and($response->headers->get('Content-Disposition'))->toBe('inline; filename="'.basename($media->path).'"');
    mediaApiAssertNotCacheable($response);
});

it('never answers 304: a conditional request gets the full file after authorization', function (string $header, string $value) {
    $media = mediaApiStored();
    Course::factory()->published()->create(['cover_media_id' => $media->id]);
    $value = str_replace('{sha}', $media->sha256, $value);

    $response = $this->get(mediaApiUrl($media), [$header => $value])->assertOk();

    expect($response->streamedContent())->toBe(Storage::disk('academy')->get($media->path));
    mediaApiAssertNotCacheable($response);
})->with([
    'If-None-Match com o hash' => ['If-None-Match', '"{sha}"'],
    'If-None-Match curinga' => ['If-None-Match', '*'],
    'If-Modified-Since' => ['If-Modified-Since', 'Fri, 01 Jan 2100 00:00:00 GMT'],
]);

it('refuses a file that stopped being public, even to a browser that claims to have it', function () {
    $media = mediaApiStored();
    $course = Course::factory()->published()->create(['cover_media_id' => $media->id]);
    $this->get(mediaApiUrl($media))->assertOk();

    $course->forceFill(['status' => PublicationStatus::Archived, 'archived_at' => now()])->save();

    $this->getJson(mediaApiUrl($media), ['If-None-Match' => '"'.$media->sha256.'"', 'If-Modified-Since' => 'Fri, 01 Jan 2100 00:00:00 GMT'])
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
});

it('serves an image only while its lesson and every ancestor are published', function (PublicationStatus $course, PublicationStatus $module, PublicationStatus $lesson) {
    $media = mediaApiStored();
    mediaApiInLesson($media, $course, $module, $lesson);
    $public = $course === PublicationStatus::Published && $module === PublicationStatus::Published && $lesson === PublicationStatus::Published;

    $response = $this->get(mediaApiUrl($media))->assertStatus($public ? 200 : 404);
    if ($public) {
        mediaApiAssertNotCacheable($response);
    }
})->with(mediaApiStates());

it('does not let a visitor reuse what an editor was allowed to see', function () {
    $media = mediaApiStored();
    mediaApiInLesson($media, PublicationStatus::Published, PublicationStatus::Published, PublicationStatus::Draft);
    mediaApiGet('editor', mediaApiUrl($media))->assertOk();

    // Próxima requisição sem o usuário (o actingAs só vale para as instâncias atuais dos guards).
    app('auth')->forgetGuards();

    $this->getJson(mediaApiUrl($media), ['If-None-Match' => '"'.$media->sha256.'"'])->assertNotFound();
});

it('does not serve the cover of a draft or archived course to visitors', function (PublicationStatus $state) {
    $media = mediaApiStored();
    Course::factory()->status($state)->create(['cover_media_id' => $media->id]);

    $this->getJson(mediaApiUrl($media))->assertNotFound()->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
})->with([PublicationStatus::Draft, PublicationStatus::Archived]);

it('serves unpublished media only to editors, without caching', function (?string $role, int $status) {
    $media = mediaApiStored();
    mediaApiInLesson($media, PublicationStatus::Published, PublicationStatus::Published, PublicationStatus::Draft);

    $response = mediaApiGet($role, mediaApiUrl($media))->assertStatus($status);

    if ($status === 200) {
        expect($response->streamedContent())->toBe(Storage::disk('academy')->get($media->path));
        mediaApiAssertNotCacheable($response);
    }
})->with([
    'visitante' => [null, 404],
    'user' => ['user', 404],
    'editor' => ['editor', 200],
    'admin' => ['admin', 200],
]);

it('does not serve an image that a public lesson only mentions outside an image block', function () {
    $media = mediaApiStored();
    $module = CourseModule::factory()->for(Course::factory()->published())->published()->create();
    $content = ['version' => 1, 'blocks' => [['type' => 'markdown', 'data' => ['text' => "## {$media->sha256}", 'media' => $media->sha256]]]];
    Lesson::factory()->forModule($module)->published()->create(['content' => $content, 'content_hash' => CanonicalJson::hash($content)]);

    $this->get(mediaApiUrl($media))->assertNotFound();
});

it('answers 404 for files on disk without a registry row (orphans)', function () {
    $contents = AcademyContent::png(4, 4, 9);
    $sha = hash('sha256', $contents);
    Storage::disk('academy')->put(MediaStore::pathFor($sha, 'png'), $contents);

    mediaApiGet('editor', "/api/v1/academy/media/{$sha}.png")->assertNotFound();
});

it('answers 404 without leaking paths when the registered file is missing or differs', function (string $problem) {
    Log::spy();
    $media = mediaApiStored();
    Course::factory()->published()->create(['cover_media_id' => $media->id]);
    match ($problem) {
        'ausente' => Storage::disk('academy')->delete($media->path),
        'tamanho diferente' => Storage::disk('academy')->put($media->path, 'truncado'),
    };

    $response = $this->getJson(mediaApiUrl($media))->assertNotFound();

    expect($response->json())->toBe(['message' => 'Not found.', 'code' => 'not_found'])
        ->and((string) $response->getContent())->not->toContain('media/')->not->toContain('storage');
    Log::shouldHaveReceived('error')->once();
})->with(['ausente', 'tamanho diferente']);

it('answers 404 when the extension in the URL does not match the registry', function () {
    $media = mediaApiStored();
    Course::factory()->published()->create(['cover_media_id' => $media->id]);

    $this->get("/api/v1/academy/media/{$media->sha256}.webp")->assertNotFound();
    $this->get("/api/v1/academy/media/{$media->sha256}.jpg")->assertNotFound();
});

it('rejects file names outside the format in the router', function (string $file) {
    $media = mediaApiStored();
    Course::factory()->published()->create(['cover_media_id' => $media->id]);
    $name = str_replace(['{sha}', '{SHA}'], [$media->sha256, strtoupper($media->sha256)], $file);

    mediaApiGet('editor', '/api/v1/academy/media/'.$name)->assertNotFound();
})->with([
    'hash em maiúsculas' => ['{SHA}.png'],
    'hash curto' => ['abc.png'],
    'extensão svg' => ['{sha}.svg'],
    'extensão dupla' => ['{sha}.png.php'],
    'sem extensão' => ['{sha}'],
    'travessia codificada' => ['..%2F..%2F.env'],
    'travessia com o hash' => ['..%2F{sha}.png'],
    'subpasta' => ['26%2F{sha}.png'],
    'byte nulo' => ['{sha}.png%00'],
]);
