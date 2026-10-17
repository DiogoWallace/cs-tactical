<?php

use App\Modules\Academy\Content\CanonicalJson;
use App\Modules\Academy\Enums\CourseLevel;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use App\Modules\Academy\Models\Quiz;
use App\Modules\Academy\Models\QuizOption;
use App\Modules\Academy\Models\QuizQuestion;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrontendSession;

/*
| API do catálogo (etapa 2.2, docs/api/academy-v1.md): só conteúdo público (R2) para todos;
| rascunhos e arquivados só com `include_unpublished=true` + `manage-academy-content` (R3);
| 404 sem revelar o que existe; quiz sem perguntas nem gabarito; número de consultas fixo.
*/

const CATALOG_STATES = [PublicationStatus::Draft, PublicationStatus::Published, PublicationStatus::Archived];

/** Campos internos ou protegidos que nenhuma resposta do catálogo pode ter, em nenhum nível. */
const CATALOG_FORBIDDEN_KEYS = [
    'id', 'key', 'position', 'course_id', 'module_id', 'lesson_id', 'cover_media_id', 'content_version',
    'published_at', 'archived_at', 'created_at', 'updated_at', 'retired_at', 'revision',
    'questions', 'options', 'prompt', 'is_correct', 'correct', 'explanation', 'snapshot',
    'sha256', 'path', 'mime', 'bytes', 'media',
];

/** null = visitante. */
function catalogActor(?string $role): ?User
{
    return match ($role) {
        null => null,
        'user' => User::factory()->create(),
        'editor' => User::factory()->editor()->create(),
        'admin' => User::factory()->admin()->create(),
    };
}

function catalogGet(?User $user, string $uri): TestResponse
{
    if ($user !== null) {
        test()->actingAs($user);
    }

    return test()->getJson($uri);
}

/**
 * Aula com conteúdo próprio (blocos já no formato do banco).
 *
 * @param  list<array{type: string, data: array<string, mixed>}>  $blocks
 * @param  array<string, mixed>  $attributes
 */
function catalogLesson(CourseModule $module, PublicationStatus $status = PublicationStatus::Published, array $blocks = [], array $attributes = []): Lesson
{
    $content = ['version' => 1, 'blocks' => $blocks ?: [['type' => 'markdown', 'data' => ['text' => '## Texto da aula']]]];

    return Lesson::factory()->forModule($module)->status($status)->create([
        ...$attributes,
        'content' => $content,
        'content_hash' => CanonicalJson::hash($content),
    ]);
}

/** @return array{Course, CourseModule, Lesson} curso, módulo e aula publicados */
function catalogPublishedTree(string $slug = 'fundamentos'): array
{
    $course = Course::factory()->published()->create(['slug' => $slug, 'key' => $slug]);
    $module = CourseModule::factory()->for($course)->published()->create(['slug' => 'movimentacao', 'position' => 1]);
    $lesson = catalogLesson($module, attributes: ['slug' => 'counter-strafe', 'position' => 1]);

    return [$course, $module, $lesson];
}

/** @return list<string> chaves de todos os níveis do JSON */
function catalogKeys(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }
    $keys = [];
    foreach ($value as $key => $child) {
        if (is_string($key)) {
            $keys[] = $key;
        }
        array_push($keys, ...catalogKeys($child));
    }

    return $keys;
}

function catalogAssertNoInternalFields(TestResponse $response): void
{
    expect(array_values(array_intersect(catalogKeys($response->json()), CATALOG_FORBIDDEN_KEYS)))->toBe([]);
}

/** @return list<string> SQL executado por `$callback` */
function catalogQueries(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $queries = array_map(fn (array $entry) => $entry['query'], DB::getQueryLog());
    DB::disableQueryLog();

    return $queries;
}

/** @return array<string, array{PublicationStatus, PublicationStatus, PublicationStatus}> */
function catalogStateMatrix(): array
{
    $matrix = [];
    foreach (CATALOG_STATES as $course) {
        foreach (CATALOG_STATES as $module) {
            foreach (CATALOG_STATES as $lesson) {
                $matrix["curso {$course->value}, módulo {$module->value}, aula {$lesson->value}"] = [$course, $module, $lesson];
            }
        }
    }

    return $matrix;
}

describe('routes', function () {
    it('are public, read-only and rate limited per real IP', function () {
        $routes = array_filter(
            Route::getRoutes()->getRoutes(),
            fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'v1.academy.'),
        );

        $summary = [];
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $summary[$route->getName()] = [
                'methods' => $route->methods(),
                'auth' => collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:')),
                'throttle' => collect($middleware)->first(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')),
            ];
        }
        ksort($summary);

        $get = ['GET', 'HEAD'];
        expect($summary)->toBe([
            'v1.academy.courses.index' => ['methods' => $get, 'auth' => false, 'throttle' => 'throttle:academy-public'],
            'v1.academy.courses.show' => ['methods' => $get, 'auth' => false, 'throttle' => 'throttle:academy-public'],
            'v1.academy.lessons.show' => ['methods' => $get, 'auth' => false, 'throttle' => 'throttle:academy-public'],
            'v1.academy.media.show' => ['methods' => $get, 'auth' => false, 'throttle' => 'throttle:academy-media'],
        ]);

        $request = Request::create('/api/v1/academy/courses', server: ['REMOTE_ADDR' => '203.0.113.7']);
        foreach (['academy-public' => 120, 'academy-media' => 600] as $name => $perMinute) {
            $limit = RateLimiter::limiter($name)($request);
            expect($limit->maxAttempts)->toBe($perMinute)
                ->and($limit->decaySeconds)->toBe(60)
                ->and($limit->key)->toBe('203.0.113.7');
        }
    });

    it('answers 429 after 120 catalog reads per minute from the same IP', function () {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/api/v1/academy/courses')->assertOk();
        }

        $this->getJson('/api/v1/academy/courses')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_requests');
    });
});

describe('GET /api/v1/academy/courses', function () {
    it('lists published courses with public counts, in catalog order', function () {
        $cover = Media::factory()->create();
        $second = Course::factory()->published()->create(['slug' => 'segundo', 'position' => 2, 'level' => CourseLevel::Advanced]);
        $first = Course::factory()->published()->create([
            'slug' => 'primeiro', 'title' => 'Primeiro', 'summary' => 'Resumo.', 'position' => 1, 'cover_media_id' => $cover->id,
        ]);
        Course::factory()->create(['slug' => 'rascunho', 'position' => 3]);
        Course::factory()->archived()->create(['slug' => 'arquivado', 'position' => 4]);

        $published = CourseModule::factory()->for($first)->published()->create(['position' => 1]);
        catalogLesson($published, attributes: ['position' => 1, 'estimated_minutes' => 10]);
        catalogLesson($published, attributes: ['position' => 2, 'estimated_minutes' => 5]);
        catalogLesson($published, PublicationStatus::Draft, attributes: ['position' => 3, 'estimated_minutes' => 100]);
        $draftModule = CourseModule::factory()->for($first)->create(['position' => 2]);
        catalogLesson($draftModule, attributes: ['position' => 1, 'estimated_minutes' => 100]);

        $response = $this->getJson('/api/v1/academy/courses')->assertOk();

        expect($response->json('data'))->toBe([
            [
                'slug' => 'primeiro',
                'title' => 'Primeiro',
                'summary' => 'Resumo.',
                'level' => 'beginner',
                'cover_image_url' => "/api/v1/academy/media/{$cover->sha256}.png",
                'module_count' => 1,
                'lesson_count' => 2,
                'estimated_minutes' => 15,
            ],
            [
                'slug' => 'segundo',
                'title' => $second->title,
                'summary' => $second->summary,
                'level' => 'advanced',
                'cover_image_url' => null,
                'module_count' => 0,
                'lesson_count' => 0,
                'estimated_minutes' => null,
            ],
        ])->and($response->json('meta'))->toBe(['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 2]);
        catalogAssertNoInternalFields($response);
    });

    it('paginates 20 per page with relative links that keep the filters', function () {
        foreach (range(1, 45) as $position) {
            Course::factory()->published()->create([
                'slug' => sprintf('curso-%02d', $position),
                'position' => 100 - $position,
                'level' => $position % 3 === 0 ? CourseLevel::Intermediate : CourseLevel::Beginner,
            ]);
        }

        $page = $this->getJson('/api/v1/academy/courses?page=3')->assertOk();
        expect(array_column($page->json('data'), 'slug'))->toBe(['curso-05', 'curso-04', 'curso-03', 'curso-02', 'curso-01'])
            ->and($page->json('meta'))->toBe(['current_page' => 3, 'last_page' => 3, 'per_page' => 20, 'total' => 45])
            ->and($page->json('links'))->toBe([
                'first' => '/api/v1/academy/courses?page=1',
                'last' => '/api/v1/academy/courses?page=3',
                'prev' => '/api/v1/academy/courses?page=2',
                'next' => null,
            ]);

        $first = $this->getJson('/api/v1/academy/courses')->assertOk();
        expect($first->json('data'))->toHaveCount(20)
            ->and($first->json('data.0.slug'))->toBe('curso-45');

        $filtered = $this->getJson('/api/v1/academy/courses?level=intermediate&utm=x')->assertOk();
        expect($filtered->json('meta.total'))->toBe(15)
            ->and(collect($filtered->json('data'))->pluck('level')->unique()->all())->toBe(['intermediate'])
            ->and($filtered->json('links.first'))->toBe('/api/v1/academy/courses?level=intermediate&page=1');

        $this->getJson('/api/v1/academy/courses?page=4')->assertOk()->assertJsonPath('data', []);
    });

    it('rejects invalid filters with 422', function (string $query, string $field) {
        $this->getJson("/api/v1/academy/courses?{$query}")
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors([$field]);
    })->with([
        'nível desconhecido' => ['level=pro', 'level'],
        'nível em maiúsculas' => ['level=BEGINNER', 'level'],
        'nível como lista' => ['level[]=beginner', 'level'],
        'página zero' => ['page=0', 'page'],
        'página negativa' => ['page=-1', 'page'],
        'página não numérica' => ['page=abc', 'page'],
        'página fracionária' => ['page=1.5', 'page'],
        'página gigante' => ['page=99999999999999999999', 'page'],
    ]);

    it('includes drafts and archived courses, with status, only for editors who ask', function (?string $role, bool $asks, bool $editorial) {
        Course::factory()->published()->create(['slug' => 'publicado', 'position' => 1]);
        $draft = Course::factory()->create(['slug' => 'rascunho', 'position' => 2]);
        Course::factory()->archived()->create(['slug' => 'arquivado', 'position' => 3]);
        catalogLesson(CourseModule::factory()->for($draft)->create(), PublicationStatus::Draft);

        $response = catalogGet(catalogActor($role), '/api/v1/academy/courses'.($asks ? '?include_unpublished=true' : ''))->assertOk();

        if ($editorial) {
            expect(array_column($response->json('data'), 'status', 'slug'))->toBe([
                'publicado' => 'published', 'rascunho' => 'draft', 'arquivado' => 'archived',
            ])->and($response->json('data.1.module_count'))->toBe(1)
                ->and($response->json('data.1.lesson_count'))->toBe(1)
                ->and($response->json('links.first'))->toBe('/api/v1/academy/courses?include_unpublished=true&page=1');
        } else {
            expect(array_column($response->json('data'), 'slug'))->toBe(['publicado'])
                ->and($response->json('data.0'))->not->toHaveKey('status');
        }
    })->with([
        'visitante pedindo' => [null, true, false],
        'user pedindo' => ['user', true, false],
        'editor sem pedir' => ['editor', false, false],
        'editor pedindo' => ['editor', true, true],
        'admin pedindo' => ['admin', true, true],
    ]);

    it('only treats true or 1 as asking for unpublished content', function (string $value, bool $editorial) {
        Course::factory()->create(['slug' => 'rascunho']);

        $response = catalogGet(catalogActor('editor'), "/api/v1/academy/courses?include_unpublished={$value}")->assertOk();

        expect($response->json('meta.total'))->toBe($editorial ? 1 : 0);
    })->with([
        'true' => ['true', true],
        '1' => ['1', true],
        'false' => ['false', false],
        '0' => ['0', false],
        'yes' => ['yes', false],
        'vazio' => ['', false],
    ]);
});

describe('GET /api/v1/academy/courses/{course}', function () {
    it('returns the public modules and lessons in order, without progress', function () {
        $cover = Media::factory()->create();
        $course = Course::factory()->published()->create([
            'slug' => 'fundamentos', 'title' => 'Fundamentos', 'summary' => 'Resumo.', 'level' => CourseLevel::Intermediate,
            'cover_media_id' => $cover->id,
        ]);
        $second = CourseModule::factory()->for($course)->published()->create(['slug' => 'mira', 'title' => 'Mira', 'position' => 2]);
        $first = CourseModule::factory()->for($course)->published()->create(['slug' => 'movimentacao', 'title' => 'Movimentação', 'position' => 1]);
        CourseModule::factory()->for($course)->create(['slug' => 'economia', 'position' => 3]);
        CourseModule::factory()->for($course)->archived()->create(['slug' => 'antigo', 'position' => 4]);

        $peek = catalogLesson($first, attributes: ['slug' => 'peek', 'title' => 'Peek', 'position' => 2]);
        $strafe = catalogLesson($first, attributes: ['slug' => 'counter-strafe', 'title' => 'Counter-strafe', 'position' => 1, 'estimated_minutes' => 8]);
        catalogLesson($first, PublicationStatus::Draft, attributes: ['slug' => 'rascunho', 'position' => 3]);
        catalogLesson($first, PublicationStatus::Archived, attributes: ['slug' => 'arquivada', 'position' => 4]);
        catalogLesson($second, attributes: ['slug' => 'crosshair', 'title' => 'Crosshair', 'position' => 1]);
        Quiz::factory()->for($strafe)->create();
        Quiz::factory()->for($peek)->create(['retired_at' => now()]);

        $response = $this->getJson('/api/v1/academy/courses/fundamentos')->assertOk();

        expect($response->json('data'))->toBe([
            'slug' => 'fundamentos',
            'title' => 'Fundamentos',
            'summary' => 'Resumo.',
            'level' => 'intermediate',
            'cover_image_url' => "/api/v1/academy/media/{$cover->sha256}.png",
            'modules' => [
                [
                    'slug' => 'movimentacao',
                    'title' => 'Movimentação',
                    'lessons' => [
                        ['slug' => 'counter-strafe', 'title' => 'Counter-strafe', 'estimated_minutes' => 8, 'has_quiz' => true],
                        ['slug' => 'peek', 'title' => 'Peek', 'estimated_minutes' => null, 'has_quiz' => false],
                    ],
                ],
                [
                    'slug' => 'mira',
                    'title' => 'Mira',
                    'lessons' => [
                        ['slug' => 'crosshair', 'title' => 'Crosshair', 'estimated_minutes' => null, 'has_quiz' => false],
                    ],
                ],
            ],
        ]);
        catalogAssertNoInternalFields($response);
    });

    it('answers 404 for courses that are not public, the same way as for missing ones', function (string $slug) {
        Course::factory()->create(['slug' => 'rascunho']);
        Course::factory()->archived()->create(['slug' => 'arquivado']);

        $missing = $this->getJson('/api/v1/academy/courses/nao-existe')->assertNotFound();
        $response = $this->getJson("/api/v1/academy/courses/{$slug}")->assertNotFound();

        expect($response->json())->toBe(['message' => 'Not found.', 'code' => 'not_found'])
            ->and($response->json())->toBe($missing->json());
    })->with(['rascunho', 'arquivado']);

    it('shows every module and lesson, with status, to editors who ask', function (?string $role, bool $asks, int $status) {
        $course = Course::factory()->archived()->create(['slug' => 'arquivado']);
        $module = CourseModule::factory()->for($course)->create(['slug' => 'modulo']);
        catalogLesson($module, PublicationStatus::Draft, attributes: ['slug' => 'aula']);

        $response = catalogGet(catalogActor($role), '/api/v1/academy/courses/arquivado'.($asks ? '?include_unpublished=true' : ''))
            ->assertStatus($status);

        if ($status === 200) {
            expect($response->json('data.status'))->toBe('archived')
                ->and($response->json('data.modules.0.status'))->toBe('draft')
                ->and($response->json('data.modules.0.lessons.0.status'))->toBe('draft');
        }
    })->with([
        'visitante pedindo' => [null, true, 404],
        'user pedindo' => ['user', true, 404],
        'editor sem pedir' => ['editor', false, 404],
        'editor pedindo' => ['editor', true, 200],
        'admin pedindo' => ['admin', true, 200],
    ]);

    it('rejects slugs outside the format in the router', function (string $slug) {
        $this->getJson('/api/v1/academy/courses/'.$slug)->assertNotFound()->assertJsonPath('code', 'not_found');
    })->with([
        'maiúsculas' => ['Fundamentos'],
        'hífen no fim' => ['fundamentos-'],
        'hífen duplo' => ['fundamentos--cs2'],
        'sublinhado' => ['fundamentos_cs2'],
        'aspas (SQL)' => ["a'%20or%20'1'='1"],
        'byte nulo' => ['fundamentos%00'],
        'travessia' => ['..%2F..%2Fetc'],
    ]);
});

describe('GET /api/v1/academy/courses/{course}/lessons/{lesson}', function () {
    it('returns the lesson with resolved blocks and public neighbours', function () {
        $course = Course::factory()->published()->create(['slug' => 'fundamentos', 'title' => 'Fundamentos']);
        $first = CourseModule::factory()->for($course)->published()->create(['slug' => 'movimentacao', 'title' => 'Movimentação', 'position' => 1]);
        $hidden = CourseModule::factory()->for($course)->create(['position' => 2]);
        $last = CourseModule::factory()->for($course)->published()->create(['position' => 3]);
        $image = Media::factory()->create(['width' => 1280, 'height' => 720]);

        catalogLesson($first, attributes: ['slug' => 'introducao', 'title' => 'Introdução', 'position' => 1]);
        catalogLesson($first, PublicationStatus::Draft, attributes: ['slug' => 'rascunho', 'position' => 2]);
        $lesson = catalogLesson($first, blocks: [
            ['type' => 'markdown', 'data' => ['text' => "## Por que parar\n\nTexto."]],
            ['type' => 'callout', 'data' => ['variant' => 'tip', 'title' => null, 'text' => 'Use **D**.']],
            ['type' => 'video', 'data' => ['provider' => 'youtube', 'video_id' => 'AbCdEfGhIjK', 'title' => 'Demonstração', 'start_seconds' => null]],
            ['type' => 'image', 'data' => ['media' => $image->sha256, 'alt' => 'Gráfico', 'decorative' => false, 'caption' => 'Legenda']],
            ['type' => 'quiz_ref', 'data' => ['quiz' => 'counter-strafe-quiz']],
        ], attributes: ['slug' => 'counter-strafe', 'title' => 'Counter-strafe', 'summary' => 'Parar para atirar.', 'position' => 3, 'estimated_minutes' => 8]);
        catalogLesson($hidden, attributes: ['slug' => 'escondida', 'position' => 1]);
        catalogLesson($last, attributes: ['slug' => 'peek', 'title' => 'Peek seguro', 'position' => 1]);

        $quiz = Quiz::factory()->for($lesson)->create(['key' => 'counter-strafe-quiz', 'title' => 'Teste rápido', 'max_attempts' => 4, 'pass_percent' => 80]);
        QuizQuestion::factory()->for($quiz)->count(3)->create();
        QuizQuestion::factory()->for($quiz)->create(['retired_at' => now()]);

        $response = $this->getJson('/api/v1/academy/courses/fundamentos/lessons/counter-strafe')->assertOk();

        expect($response->json('data'))->toBe([
            'slug' => 'counter-strafe',
            'title' => 'Counter-strafe',
            'summary' => 'Parar para atirar.',
            'estimated_minutes' => 8,
            'course' => ['slug' => 'fundamentos', 'title' => 'Fundamentos'],
            'module' => ['slug' => 'movimentacao', 'title' => 'Movimentação'],
            'content' => [
                'version' => 1,
                'blocks' => [
                    ['type' => 'markdown', 'data' => ['text' => "## Por que parar\n\nTexto."]],
                    ['type' => 'callout', 'data' => ['variant' => 'tip', 'title' => null, 'text' => 'Use **D**.']],
                    ['type' => 'video', 'data' => ['provider' => 'youtube', 'video_id' => 'AbCdEfGhIjK', 'title' => 'Demonstração', 'start_seconds' => null]],
                    ['type' => 'image', 'data' => [
                        'url' => "/api/v1/academy/media/{$image->sha256}.png", 'alt' => 'Gráfico', 'caption' => 'Legenda', 'width' => 1280, 'height' => 720,
                    ]],
                    ['type' => 'quiz_ref', 'data' => [
                        'quiz' => 'counter-strafe-quiz', 'title' => 'Teste rápido', 'question_count' => 3, 'max_attempts' => 4, 'pass_percent' => 80,
                    ]],
                ],
            ],
            'content_hash' => $lesson->content_hash,
            'previous' => ['slug' => 'introducao', 'title' => 'Introdução'],
            'next' => ['slug' => 'peek', 'title' => 'Peek seguro'],
        ]);
    });

    it('has no previous on the first public lesson and no next on the last', function () {
        [$course, $module] = catalogPublishedTree();
        catalogLesson($module, attributes: ['slug' => 'segunda', 'position' => 2]);

        $this->getJson('/api/v1/academy/courses/fundamentos/lessons/counter-strafe')
            ->assertOk()->assertJsonPath('data.previous', null)->assertJsonPath('data.next.slug', 'segunda');
        $this->getJson('/api/v1/academy/courses/fundamentos/lessons/segunda')
            ->assertOk()->assertJsonPath('data.previous.slug', 'counter-strafe')->assertJsonPath('data.next', null);
    });

    it('never exposes questions, options, answers, explanations or internal fields', function () {
        [, $module] = catalogPublishedTree();
        $lesson = catalogLesson($module, blocks: [
            ['type' => 'markdown', 'data' => ['text' => '## Aula']],
            ['type' => 'quiz_ref', 'data' => ['quiz' => 'quiz-protegido']],
        ], attributes: ['slug' => 'com-quiz', 'position' => 2]);
        Quiz::factory()->for($lesson)->create(['key' => 'quiz-antigo', 'title' => 'QUIZ-APOSENTADO', 'retired_at' => now()]);
        $quiz = Quiz::factory()->for($lesson)->create(['key' => 'quiz-protegido', 'title' => 'Quiz']);
        $question = QuizQuestion::factory()->for($quiz)->create(['prompt' => 'PERGUNTA-SECRETA?', 'explanation' => 'EXPLICACAO-SECRETA']);
        $right = QuizOption::factory()->for($question, 'question')->create(['text' => 'OPCAO-CERTA', 'is_correct' => true]);
        QuizOption::factory()->for($question, 'question')->create(['text' => 'OPCAO-ERRADA']);

        foreach ([null, 'editor'] as $role) {
            $response = catalogGet(catalogActor($role), '/api/v1/academy/courses/fundamentos/lessons/com-quiz?include_unpublished=true')->assertOk();
            $body = (string) $response->getContent();

            expect($response->json('data.content.blocks.1.data'))->toBe([
                'quiz' => 'quiz-protegido', 'title' => 'Quiz', 'question_count' => 1, 'max_attempts' => 3, 'pass_percent' => 70,
            ]);
            foreach (['PERGUNTA-SECRETA', 'EXPLICACAO-SECRETA', 'OPCAO-CERTA', 'OPCAO-ERRADA', 'QUIZ-APOSENTADO', $quiz->id, $question->id, $right->id, $lesson->id] as $secret) {
                expect($body)->not->toContain($secret);
            }
            catalogAssertNoInternalFields($response);
        }

        $course = $this->getJson('/api/v1/academy/courses/fundamentos')->assertOk();
        catalogAssertNoInternalFields($course);
        expect((string) $course->getContent())->not->toContain('PERGUNTA-SECRETA')->not->toContain('OPCAO-CERTA');
    });

    it('drops blocks that point to missing media, a different quiz or an unknown type', function () {
        [, $module] = catalogPublishedTree();
        $lesson = catalogLesson($module, blocks: [
            ['type' => 'markdown', 'data' => ['text' => '## Aula', 'html' => '<script>x</script>']],
            ['type' => 'image', 'data' => ['media' => str_repeat('a', 64), 'alt' => 'Sem registro', 'decorative' => false, 'caption' => null]],
            ['type' => 'quiz_ref', 'data' => ['quiz' => 'outro-quiz']],
            ['type' => 'iframe', 'data' => ['src' => 'https://example.com']],
        ], attributes: ['slug' => 'inconsistente', 'position' => 2]);
        Quiz::factory()->for($lesson)->create(['key' => 'quiz-da-aula']);

        $response = $this->getJson('/api/v1/academy/courses/fundamentos/lessons/inconsistente')->assertOk();

        expect($response->json('data.content.blocks'))->toBe([['type' => 'markdown', 'data' => ['text' => '## Aula']]]);
    });

    it('answers 404 for a lesson of another course or a slug that does not exist', function () {
        catalogPublishedTree('fundamentos');
        [, $module] = catalogPublishedTree('avancado');
        catalogLesson($module, attributes: ['slug' => 'so-no-avancado', 'position' => 2]);

        $this->getJson('/api/v1/academy/courses/fundamentos/lessons/so-no-avancado')->assertNotFound();
        $this->getJson('/api/v1/academy/courses/fundamentos/lessons/nao-existe')->assertNotFound();
        $this->getJson('/api/v1/academy/courses/nao-existe/lessons/counter-strafe')->assertNotFound();
        $this->getJson('/api/v1/academy/courses/fundamentos/lessons/Counter-Strafe')->assertNotFound();
        $this->getJson('/api/v1/academy/courses/fundamentos/lessons/so-no-avancado?include_unpublished=true')->assertNotFound();
    });

    it('lets editors who ask read archived and draft lessons, with status', function (?string $role, bool $asks, int $status) {
        $course = Course::factory()->published()->create(['slug' => 'fundamentos']);
        $module = CourseModule::factory()->for($course)->archived()->create(['slug' => 'antigo']);
        catalogLesson($module, PublicationStatus::Draft, attributes: ['slug' => 'publicada-antes', 'position' => 1]);
        catalogLesson($module, PublicationStatus::Archived, attributes: ['slug' => 'arquivada', 'position' => 2]);

        $response = catalogGet(catalogActor($role), '/api/v1/academy/courses/fundamentos/lessons/arquivada'.($asks ? '?include_unpublished=1' : ''))
            ->assertStatus($status);

        if ($status === 200) {
            expect($response->json('data.status'))->toBe('archived')
                ->and($response->json('data.module.status'))->toBe('archived')
                ->and($response->json('data.course.status'))->toBe('published')
                ->and($response->json('data.previous.slug'))->toBe('publicada-antes');
        } else {
            expect($response->json())->toBe(['message' => 'Not found.', 'code' => 'not_found']);
        }
    })->with([
        'visitante' => [null, false, 404],
        'visitante pedindo' => [null, true, 404],
        'user pedindo' => ['user', true, 404],
        'editor sem pedir' => ['editor', false, 404],
        'editor pedindo' => ['editor', true, 200],
        'admin sem pedir' => ['admin', false, 404],
        'admin pedindo' => ['admin', true, 200],
    ]);

    it('reads without writing anything (R10)', function () {
        catalogPublishedTree();

        $queries = catalogQueries(fn () => $this->getJson('/api/v1/academy/courses/fundamentos/lessons/counter-strafe')->assertOk());

        expect($queries)->not->toBeEmpty()
            ->and(array_filter($queries, fn (string $sql) => ! str_starts_with(strtolower(ltrim($sql)), 'select')))->toBe([]);
    });
});

describe('effective visibility over HTTP (R2)', function () {
    it('shows a lesson only when the course, the module and the lesson are published', function (PublicationStatus $courseStatus, PublicationStatus $moduleStatus, PublicationStatus $lessonStatus) {
        $course = Course::factory()->status($courseStatus)->create(['slug' => 'curso']);
        $module = CourseModule::factory()->for($course)->status($moduleStatus)->create(['slug' => 'modulo']);
        catalogLesson($module, $lessonStatus, attributes: ['slug' => 'aula']);

        $published = PublicationStatus::Published;
        $coursePublic = $courseStatus === $published;
        $modulePublic = $coursePublic && $moduleStatus === $published;
        $lessonPublic = $modulePublic && $lessonStatus === $published;

        $this->getJson('/api/v1/academy/courses/curso/lessons/aula')->assertStatus($lessonPublic ? 200 : 404);

        $detail = $this->getJson('/api/v1/academy/courses/curso')->assertStatus($coursePublic ? 200 : 404);
        if ($coursePublic) {
            expect(array_column($detail->json('data.modules'), 'slug'))->toBe($modulePublic ? ['modulo'] : [])
                ->and($modulePublic ? array_column($detail->json('data.modules.0.lessons'), 'slug') : [])->toBe($lessonPublic ? ['aula'] : []);
        }

        $list = $this->getJson('/api/v1/academy/courses')->assertOk();
        expect(array_column($list->json('data'), 'slug'))->toBe($coursePublic ? ['curso'] : []);
        if ($coursePublic) {
            expect($list->json('data.0.module_count'))->toBe($modulePublic ? 1 : 0)
                ->and($list->json('data.0.lesson_count'))->toBe($lessonPublic ? 1 : 0);
        }

        // Modo editorial: tudo aparece, com o estado de cada nível.
        $editor = User::factory()->editor()->create();
        $this->actingAs($editor)->getJson('/api/v1/academy/courses/curso/lessons/aula?include_unpublished=true')
            ->assertOk()
            ->assertJsonPath('data.status', $lessonStatus->value)
            ->assertJsonPath('data.module.status', $moduleStatus->value)
            ->assertJsonPath('data.course.status', $courseStatus->value);
    })->with(catalogStateMatrix());
});

describe('editorial access through the real frontend session', function () {
    it('uses the session user on public routes and ignores the parameter without one', function () {
        Course::factory()->create(['slug' => 'rascunho']);
        User::factory()->editor()->create(['email' => 'editora@example.com']);

        $visitor = new FrontendSession(test());
        $visitor->get('/api/v1/academy/courses/rascunho?include_unpublished=true')->assertNotFound();

        $session = new FrontendSession(test());
        $session->csrfCookie()->assertNoContent();
        $session->post('/api/v1/auth/login', ['email' => 'editora@example.com', 'password' => 'password'])->assertOk();

        $session->get('/api/v1/academy/courses/rascunho?include_unpublished=true')
            ->assertOk()->assertJsonPath('data.status', 'draft');
        $session->get('/api/v1/academy/courses/rascunho')->assertNotFound();
    });
});

/** Curso publicado com capa e 3 módulos × 3 aulas, cada aula com imagem e quiz. */
function catalogRichCourse(string $slug, int $position): void
{
    $course = Course::factory()->published()->create(['slug' => $slug, 'position' => $position, 'cover_media_id' => Media::factory()->create()->id]);
    foreach (range(1, 3) as $m) {
        $module = CourseModule::factory()->for($course)->published()->create(['position' => $m]);
        foreach (range(1, 3) as $l) {
            $image = Media::factory()->create();
            $lesson = catalogLesson($module, blocks: [
                ['type' => 'image', 'data' => ['media' => $image->sha256, 'alt' => 'Imagem', 'decorative' => false, 'caption' => null]],
                ['type' => 'quiz_ref', 'data' => ['quiz' => "quiz-{$slug}-{$m}-{$l}"]],
            ], attributes: ['slug' => "aula-{$m}-{$l}", 'position' => $l]);
            Quiz::factory()->for($lesson)->create(['key' => "quiz-{$slug}-{$m}-{$l}"]);
        }
    }
}

describe('query count (no N+1)', function () {
    it('lists courses with a constant number of queries', function () {
        catalogRichCourse('curso-1', 1);
        $this->getJson('/api/v1/academy/courses')->assertOk();
        $one = catalogQueries(fn () => $this->getJson('/api/v1/academy/courses')->assertOk());

        foreach (range(2, 6) as $i) {
            catalogRichCourse("curso-{$i}", $i);
        }
        $many = catalogQueries(fn () => $this->getJson('/api/v1/academy/courses')->assertOk()->assertJsonCount(6, 'data'));

        expect(count($many))->toBe(count($one))->toBeLessThanOrEqual(3);
    });

    it('shows a course with a constant number of queries', function () {
        catalogRichCourse('pequeno', 1);
        $small = catalogQueries(fn () => $this->getJson('/api/v1/academy/courses/pequeno')->assertOk());

        catalogRichCourse('grande', 2);
        $module = CourseModule::factory()->for(Course::query()->where('slug', 'grande')->firstOrFail())->published()->create(['position' => 9]);
        foreach (range(1, 10) as $l) {
            catalogLesson($module, attributes: ['position' => $l]);
        }
        $large = catalogQueries(fn () => $this->getJson('/api/v1/academy/courses/grande')->assertOk()->assertJsonCount(4, 'data.modules'));

        expect(count($large))->toBe(count($small))->toBeLessThanOrEqual(4);
    });

    it('shows a lesson with a constant number of queries, whatever the number of images', function () {
        catalogRichCourse('curso', 1);
        $few = catalogQueries(fn () => $this->getJson('/api/v1/academy/courses/curso/lessons/aula-1-1')->assertOk());

        $module = CourseModule::query()->whereHas('course', fn ($q) => $q->where('slug', 'curso'))->where('position', 1)->firstOrFail();
        $images = Media::factory()->count(12)->create();
        catalogLesson($module, blocks: $images->map(fn (Media $media) => [
            'type' => 'image', 'data' => ['media' => $media->sha256, 'alt' => 'Imagem', 'decorative' => false, 'caption' => null],
        ])->all(), attributes: ['slug' => 'muitas-imagens', 'position' => 9]);

        $response = null;
        $many = catalogQueries(function () use (&$response) {
            $response = $this->getJson('/api/v1/academy/courses/curso/lessons/muitas-imagens')->assertOk();
        });

        expect($response->json('data.content.blocks'))->toHaveCount(12)
            ->and(count($many))->toBe(count($few))->toBeLessThanOrEqual(6);
    });
});
