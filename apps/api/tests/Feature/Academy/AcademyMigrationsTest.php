<?php

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Media;
use App\Modules\Academy\Models\Quiz;
use App\Modules\Academy\Models\QuizOption;
use App\Modules\Academy\Models\QuizQuestion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Integridade do catálogo da Academy no PostgreSQL (docs/database/academy-learning.md).
| Cada escrita inválida roda num savepoint: um erro no PostgreSQL abortaria a transação do teste.
| Únicos adiáveis só são verificados no COMMIT; os testes os tornam imediatos no savepoint.
*/

const ACADEMY_TABLES = ['academy_media', 'courses', 'course_modules', 'lessons', 'quizzes', 'quiz_questions', 'quiz_options'];

function violates(string $constraint, Closure $write): void
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

it('rolls back and re-applies the academy migrations', function () {
    $this->artisan('migrate:rollback', ['--step' => 5])->assertSuccessful();
    foreach (ACADEMY_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    foreach (ACADEMY_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('restricts deletion of content at the database level', function () {
    $rules = DB::table('pg_constraint')
        ->whereIn('conname', [
            'courses_cover_media_id_foreign',
            'course_modules_course_id_foreign',
            'lessons_course_id_foreign',
            'lessons_module_id_course_id_foreign',
            'quizzes_lesson_id_foreign',
            'quiz_questions_quiz_id_foreign',
            'quiz_options_question_id_foreign',
        ])
        ->pluck('confdeltype', 'conname')
        ->all();

    expect($rules)->toHaveCount(7)->each->toBe('r');
});

it('refuses to delete a course that still has modules', function () {
    $module = CourseModule::factory()->create();

    violates('course_modules_course_id_foreign', fn () => Course::query()->whereKey($module->course_id)->delete());
    expect(Course::query()->whereKey($module->course_id)->exists())->toBeTrue();
});

it('keeps a lesson inside a module of the same course', function () {
    $module = CourseModule::factory()->create();
    $otherCourse = Course::factory()->create();

    violates('lessons_module_id_course_id_foreign', fn () => Lesson::factory()->create([
        'module_id' => $module->id,
        'course_id' => $otherCourse->id,
    ]));
});

it('enforces key and slug formats and uniqueness', function () {
    $course = Course::factory()->create(['key' => 'fundamentos', 'slug' => 'fundamentos']);

    violates('courses_key_format_check', fn () => Course::factory()->create(['key' => 'Fundamentos CS']));
    violates('courses_slug_format_check', fn () => Course::factory()->create(['slug' => 'slug_invalido']));
    violates('courses_key_unique', fn () => Course::factory()->create(['key' => 'fundamentos']));
    violates('courses_slug_unique', fn () => Course::factory()->create(['slug' => 'fundamentos']));

    CourseModule::factory()->for($course)->create(['key' => 'mira', 'slug' => 'mira']);
    violates('course_modules_course_id_key_unique', fn () => CourseModule::factory()->for($course)->create(['key' => 'mira']));
    violates('course_modules_course_id_slug_unique', fn () => CourseModule::factory()->for($course)->create(['slug' => 'mira']));
    // A mesma chave em outro curso é permitida.
    expect(CourseModule::factory()->create(['key' => 'mira'])->exists)->toBeTrue();
});

it('enforces publication dates for each status', function (string $table, Closure $factory) {
    violates("{$table}_published_at_check", fn () => $factory(['status' => PublicationStatus::Published, 'published_at' => null]));
    violates("{$table}_archived_at_check", fn () => $factory(['status' => PublicationStatus::Archived, 'published_at' => now(), 'archived_at' => null]));
    // O cast de enum não aceita valor fora da lista; a escrita direta mostra que o banco também não.
    $id = $factory([])->getKey();
    violates("{$table}_status_check", fn () => DB::table($table)->where('id', $id)->update(['status' => 'hidden']));
})->with([
    // Parâmetro tipado como Closure: o Pest entrega a closure sem chamá-la.
    'courses' => ['courses', fn (array $attributes) => Course::factory()->create($attributes)],
    'course_modules' => ['course_modules', fn (array $attributes) => CourseModule::factory()->create($attributes)],
    'lessons' => ['lessons', fn (array $attributes) => Lesson::factory()->create($attributes)],
]);

it('enforces the lesson content shape and hash', function () {
    violates('lessons_content_blocks_check', fn () => Lesson::factory()->create(['content' => ['version' => 1, 'blocks' => 'texto']]));
    violates('lessons_content_version_check', fn () => Lesson::factory()->create(['content' => ['version' => 2, 'blocks' => []], 'content_version' => 1]));
    violates('lessons_content_hash_format_check', fn () => Lesson::factory()->create(['content_hash' => 'abc']));
    violates('lessons_estimated_minutes_check', fn () => Lesson::factory()->create(['estimated_minutes' => 0]));
});

it('keeps positions unique per scope, checked at commit so imports can reorder', function () {
    $module = CourseModule::factory()->create();
    $first = Lesson::factory()->forModule($module)->create(['position' => 1]);
    $second = Lesson::factory()->forModule($module)->create(['position' => 2]);

    violates('lessons_position_unique', fn () => Lesson::factory()->forModule($module)->create(['position' => 1]));

    // Trocar as posições numa transação só é verificado no fim: funciona.
    DB::transaction(function () use ($first, $second) {
        $first->forceFill(['position' => 2])->save();
        $second->forceFill(['position' => 1])->save();
        DB::statement('SET CONSTRAINTS lessons_position_unique IMMEDIATE');
    });

    expect($first->fresh()->position)->toBe(2)->and($second->fresh()->position)->toBe(1);
});

it('allows only one active quiz per lesson, keeping retired ones', function () {
    $quiz = Quiz::factory()->create();

    violates('quizzes_active_lesson_unique', fn () => Quiz::factory()->create(['lesson_id' => $quiz->lesson_id]));

    $quiz->forceFill(['retired_at' => now()])->save();
    $replacement = Quiz::factory()->create(['lesson_id' => $quiz->lesson_id]);

    expect($replacement->exists)->toBeTrue()
        ->and(Quiz::query()->where('lesson_id', $quiz->lesson_id)->count())->toBe(2);
});

it('enforces quiz limits and question and option rules', function () {
    violates('quizzes_max_attempts_check', fn () => Quiz::factory()->create(['max_attempts' => 0]));
    violates('quizzes_pass_percent_check', fn () => Quiz::factory()->create(['pass_percent' => 101]));
    $questionId = QuizQuestion::factory()->create()->id;
    violates('quiz_questions_kind_check', fn () => DB::table('quiz_questions')->where('id', $questionId)->update(['kind' => 'free_text']));
    violates('quiz_questions_prompt_check', fn () => QuizQuestion::factory()->create(['prompt' => str_repeat('a', 1001)]));

    $question = QuizQuestion::factory()->create();
    QuizOption::factory()->for($question, 'question')->create(['position' => 0]);
    violates('quiz_options_active_position_unique', fn () => QuizOption::factory()->for($question, 'question')->create(['position' => 0]));
    violates('quiz_options_text_not_blank_check', fn () => QuizOption::factory()->for($question, 'question')->create(['text' => '   ']));
});

it('derives the media path from the hash and restricts formats', function () {
    $sha = hash('sha256', 'imagem');

    violates('academy_media_path_check', fn () => Media::factory()->create(['sha256' => $sha, 'path' => "media/xx/{$sha}.png"]));
    violates('academy_media_mime_check', fn () => Media::factory()->create(['sha256' => $sha, 'mime' => 'image/svg+xml', 'path' => 'media/'.substr($sha, 0, 2)."/{$sha}.svg"]));
    violates('academy_media_bytes_check', fn () => Media::factory()->create(['bytes' => 3 * 1024 * 1024]));
    violates('academy_media_dimensions_check', fn () => Media::factory()->create(['width' => 5000]));

    $media = Media::factory()->create();
    Course::factory()->create(['cover_media_id' => $media->id]);
    violates('courses_cover_media_id_foreign', fn () => Media::query()->whereKey($media->id)->delete());
});
