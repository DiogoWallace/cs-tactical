<?php

namespace Tests\Support;

use App\Modules\Academy\Content\CanonicalJson;
use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Identity\Models\User;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;

/**
 * Cenários dos testes do Learning (etapa 2.4): cursos com módulos e aulas em estados escolhidos,
 * requisições como um jogador e contagem de escritas e consultas.
 */
final class LearningFixtures
{
    /** Campos internos que nenhuma resposta do Learning pode ter, em nenhum nível. */
    public const FORBIDDEN_KEYS = [
        'id', 'key', 'position', 'user_id', 'course_id', 'module_id', 'lesson_id', 'content_hash',
        'completed_content_hash', 'started_at', 'created_at', 'updated_at', 'email', 'platform_role',
        'questions', 'options', 'is_correct', 'correct', 'explanation', 'snapshot',
    ];

    /**
     * Curso público "fundamentos" com dois módulos e as aulas indicadas (todas publicadas):
     * módulo 1: counter-strafe, peek; módulo 2: mira. Devolve [curso, módulos por slug, aulas por slug].
     *
     * @return array{Course, array<string, CourseModule>, array<string, Lesson>}
     */
    public static function course(string $slug = 'fundamentos'): array
    {
        $course = Course::factory()->published()->create(['slug' => $slug, 'key' => $slug]);
        $movimentacao = CourseModule::factory()->for($course)->published()->create(['slug' => 'movimentacao', 'key' => 'movimentacao', 'position' => 1]);
        $mira = CourseModule::factory()->for($course)->published()->create(['slug' => 'mira', 'key' => 'mira', 'position' => 2]);

        $lessons = [
            'counter-strafe' => self::lesson($movimentacao, 'counter-strafe', 1),
            'peek' => self::lesson($movimentacao, 'peek', 2),
            'mira' => self::lesson($mira, 'mira', 1),
        ];

        return [$course, ['movimentacao' => $movimentacao, 'mira' => $mira], $lessons];
    }

    public static function lesson(CourseModule $module, string $slug, int $position, PublicationStatus $status = PublicationStatus::Published, string $text = 'Texto da aula'): Lesson
    {
        $content = ['version' => 1, 'blocks' => [['type' => 'markdown', 'data' => ['text' => "## {$text}"]]]];

        return Lesson::factory()->forModule($module)->status($status)->create([
            'slug' => $slug,
            'key' => $slug,
            'title' => ucfirst(str_replace('-', ' ', $slug)),
            'position' => $position,
            'content' => $content,
            'content_hash' => CanonicalJson::hash($content),
        ]);
    }

    /** Muda o conteúdo da aula como a importação faria (novo hash). */
    public static function editContent(Lesson $lesson, string $text): void
    {
        $content = ['version' => 1, 'blocks' => [['type' => 'markdown', 'data' => ['text' => "## {$text}"]]]];
        $lesson->forceFill(['content' => $content, 'content_hash' => CanonicalJson::hash($content)])->save();
    }

    /** Muda o status como a importação faria (com as datas exigidas pelos checks). */
    public static function setStatus(Course|CourseModule|Lesson $model, PublicationStatus $status): void
    {
        $model->forceFill([
            'status' => $status,
            'published_at' => $model->published_at ?? now(),
            'archived_at' => $status === PublicationStatus::Archived ? now() : null,
        ])->save();
    }

    public static function as(?User $user): void
    {
        if ($user !== null) {
            test()->actingAs($user);
        }
        test()->withHeader('Origin', FrontendSession::ORIGIN);
    }

    public static function enroll(?User $user, string $course = 'fundamentos'): TestResponse
    {
        self::as($user);

        return test()->putJson("/api/v1/academy/courses/{$course}/enrollment");
    }

    public static function complete(?User $user, string $lesson, string $course = 'fundamentos'): TestResponse
    {
        self::as($user);

        return test()->putJson("/api/v1/academy/courses/{$course}/lessons/{$lesson}/completion");
    }

    public static function progress(?User $user, string $course = 'fundamentos'): TestResponse
    {
        self::as($user);

        return test()->getJson("/api/v1/academy/courses/{$course}/progress");
    }

    public static function enrollments(?User $user): TestResponse
    {
        self::as($user);

        return test()->getJson('/api/v1/me/enrollments');
    }

    /** @return array{queries: list<string>, writes: list<string>} SQL executado por `$callback` */
    public static function sql(Closure $callback): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = array_map(fn (array $entry) => $entry['query'], DB::getQueryLog());
        DB::disableQueryLog();

        return [
            'queries' => $queries,
            'writes' => array_values(array_filter($queries, fn (string $sql) => preg_match('/^\s*(insert|update|delete)\b/i', $sql) === 1)),
        ];
    }

    /** @return list<string> chaves de todos os níveis do JSON */
    public static function keys(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $keys = [];
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...self::keys($child));
        }

        return $keys;
    }

    public static function assertNoInternalFields(TestResponse $response): void
    {
        expect(array_values(array_intersect(self::keys($response->json()), self::FORBIDDEN_KEYS)))->toBe([]);
    }

    /** A escrita viola a constraint (num savepoint, para não abortar a transação do teste). */
    public static function violates(string $constraint, Closure $write): void
    {
        try {
            DB::transaction(function () use ($write) {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
                $write();
            });
        } catch (QueryException $e) {
            expect($e->getMessage())->toContain($constraint);

            return;
        }

        throw new RuntimeException("A escrita deveria violar {$constraint}.");
    }
}
