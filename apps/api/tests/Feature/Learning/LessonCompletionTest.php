<?php

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use Illuminate\Support\Facades\DB;
use Tests\Support\LearningFixtures;

/*
| PUT /api/v1/academy/courses/{course}/lessons/{lesson}/completion (R8, R9, R12; ADR 0017).
*/

beforeEach(fn () => config(['sanctum.stateful' => ['localhost:3000']]));

/** @return array<string, array{PublicationStatus, PublicationStatus, PublicationStatus}> */
function learningStateMatrix(): array
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

it('requires a session', function () {
    LearningFixtures::course();

    LearningFixtures::complete(null, 'peek')->assertUnauthorized();
    expect(LessonProgress::query()->count())->toBe(0);
});

it('completes a public lesson, enrolls automatically and returns the course progress', function () {
    [$course, , $lessons] = LearningFixtures::course();
    $user = User::factory()->create();

    $response = LearningFixtures::complete($user, 'peek')->assertOk()
        ->assertJsonPath('data.enrolled', true)
        ->assertJsonPath('data.percent', 33.33)
        ->assertJsonPath('data.completed_lessons', 1)
        ->assertJsonPath('data.total_lessons', 3)
        ->assertJsonPath('data.completed_at', null)
        ->assertJsonPath('data.next_lesson', ['slug' => 'counter-strafe', 'title' => 'Counter strafe'])
        ->assertJsonPath('data.lessons.1.slug', 'peek')
        ->assertJsonPath('data.lessons.1.status', 'completed')
        ->assertJsonPath('data.lessons.1.updated_since_completion', false)
        ->assertJsonPath('data.lessons.0.status', 'not_started');
    LearningFixtures::assertNoInternalFields($response);

    $progress = LessonProgress::query()->sole();
    expect([$progress->user_id, $progress->lesson_id, $progress->course_id])->toBe([$user->id, $lessons['peek']->id, $course->id])
        ->and($progress->completed_content_hash)->toBe($lessons['peek']->content_hash)
        ->and(Enrollment::query()->where('user_id', $user->id)->where('course_id', $course->id)->count())->toBe(1);
});

it('is idempotent: completing again keeps the first date and writes nothing', function () {
    LearningFixtures::course();
    $user = User::factory()->create();
    $this->freezeSecond();
    $first = LearningFixtures::complete($user, 'peek')->json('data.lessons.1.completed_at');

    $this->travel(2)->hours();
    $sql = LearningFixtures::sql(fn () => LearningFixtures::complete($user, 'peek')->assertOk()
        ->assertJsonPath('data.lessons.1.completed_at', $first));

    expect($sql['writes'])->toBe([])
        ->and(LessonProgress::query()->count())->toBe(1)
        ->and(Enrollment::query()->sole()->last_activity_at->toIso8601ZuluString())->toBe($first);
});

it('records activity on each new completion, for "continue where you left off"', function () {
    LearningFixtures::course();
    $user = User::factory()->create();
    LearningFixtures::complete($user, 'counter-strafe');

    $this->travel(1)->hour();
    LearningFixtures::complete($user, 'peek');

    $enrollment = Enrollment::query()->sole();
    expect($enrollment->last_activity_at->greaterThan($enrollment->enrolled_at))->toBeTrue();
});

it('completes only lessons whose course, module and lesson are all published', function (PublicationStatus $courseState, PublicationStatus $moduleState, PublicationStatus $lessonState) {
    $course = Course::factory()->status($courseState)->create(['slug' => 'curso']);
    $module = CourseModule::factory()->for($course)->status($moduleState)->create();
    LearningFixtures::lesson($module, 'aula', 1, $lessonState);
    $public = $courseState === PublicationStatus::Published && $moduleState === PublicationStatus::Published && $lessonState === PublicationStatus::Published;

    $response = LearningFixtures::complete(User::factory()->editor()->create(), 'aula', 'curso');

    if ($public) {
        $response->assertOk();
        expect(LessonProgress::query()->count())->toBe(1);
    } else {
        $response->assertNotFound()->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
        expect(LessonProgress::query()->count())->toBe(0)->and(Enrollment::query()->count())->toBe(0);
    }
})->with(learningStateMatrix());

it('refuses manipulated paths: a lesson of another course, or an unknown one', function (string $course, string $lesson) {
    LearningFixtures::course();
    [, , $others] = LearningFixtures::course('outro-curso');
    $others['mira']->forceFill(['slug' => 'exclusiva'])->save();

    LearningFixtures::complete(User::factory()->create(), $lesson, $course)->assertNotFound();
    expect(LessonProgress::query()->count())->toBe(0)->and(Enrollment::query()->count())->toBe(0);
})->with([
    'aula de outro curso' => ['fundamentos', 'exclusiva'],
    'aula inexistente' => ['fundamentos', 'nao-existe'],
    'curso inexistente' => ['nao-existe', 'peek'],
    'slug malformado' => ['fundamentos', 'Peek'],
]);

it('marks the course as completed with the last public lesson and never clears it (R12)', function () {
    [, $modules] = LearningFixtures::course();
    $user = User::factory()->create();
    LearningFixtures::complete($user, 'counter-strafe')->assertJsonPath('data.completed_at', null);
    LearningFixtures::complete($user, 'peek');
    $done = LearningFixtures::complete($user, 'mira')->assertJsonPath('data.percent', 100)
        ->assertJsonPath('data.next_lesson', null)
        ->assertJsonPath('data.new_lessons_available', false)
        ->json('data.completed_at');
    expect($done)->not->toBeNull();

    // Uma aula nova é publicada depois: o curso continua concluído, com "novas aulas disponíveis".
    LearningFixtures::lesson($modules['mira'], 'nova', 2);
    LearningFixtures::progress($user)->assertOk()
        ->assertJsonPath('data.completed_at', $done)
        ->assertJsonPath('data.percent', 75)
        ->assertJsonPath('data.new_lessons_available', true)
        ->assertJsonPath('data.next_lesson.slug', 'nova');

    LearningFixtures::complete($user, 'nova')->assertJsonPath('data.completed_at', $done)->assertJsonPath('data.percent', 100);
});

it('keeps a completion after the lesson is edited and flags the update (ADR 0017)', function () {
    [, , $lessons] = LearningFixtures::course();
    $user = User::factory()->create();
    LearningFixtures::complete($user, 'peek');

    LearningFixtures::editContent($lessons['peek'], 'Texto corrigido');

    LearningFixtures::progress($user)->assertOk()
        ->assertJsonPath('data.lessons.1.status', 'completed')
        ->assertJsonPath('data.lessons.1.updated_since_completion', true)
        ->assertJsonPath('data.completed_lessons', 1);
    // Concluir de novo não "atualiza" a conclusão: o hash daquele momento continua.
    LearningFixtures::complete($user, 'peek')->assertJsonPath('data.lessons.1.updated_since_completion', true);
});

it('keeps completions when lessons are reordered or moved to another module of the course', function () {
    [, $modules, $lessons] = LearningFixtures::course();
    $user = User::factory()->create();
    LearningFixtures::complete($user, 'counter-strafe');

    DB::transaction(function () use ($lessons, $modules) {
        $lessons['counter-strafe']->forceFill(['module_id' => $modules['mira']->id, 'position' => 2])->save();
        $lessons['peek']->forceFill(['position' => 1])->save();
    });

    LearningFixtures::progress($user)->assertOk()
        ->assertJsonPath('data.lessons.0.slug', 'peek')
        ->assertJsonPath('data.lessons.2.slug', 'counter-strafe')
        ->assertJsonPath('data.lessons.2.status', 'completed')
        ->assertJsonPath('data.next_lesson.slug', 'peek');
});

it('removes archived lessons from the percentage, keeps their history and refuses new progress on them', function () {
    [$course, , $lessons] = LearningFixtures::course();
    $user = User::factory()->create();
    LearningFixtures::complete($user, 'counter-strafe');
    LearningFixtures::complete($user, 'peek');

    LearningFixtures::setStatus($lessons['peek'], PublicationStatus::Archived);
    LearningFixtures::setStatus($lessons['mira'], PublicationStatus::Archived);

    LearningFixtures::progress($user)->assertOk()
        ->assertJsonPath('data.total_lessons', 1)
        ->assertJsonPath('data.completed_lessons', 1)
        ->assertJsonPath('data.percent', 100)
        ->assertJsonCount(1, 'data.lessons');
    LearningFixtures::complete($user, 'peek')->assertNotFound();
    LearningFixtures::complete($user, 'mira')->assertNotFound();

    expect(LessonProgress::query()->where('course_id', $course->id)->count())->toBe(2);
});

it('answers 404 for every Learning action once the course is archived, keeping the history (P1)', function () {
    [$course] = LearningFixtures::course();
    $user = User::factory()->create();
    LearningFixtures::complete($user, 'peek');

    LearningFixtures::setStatus($course, PublicationStatus::Archived);

    LearningFixtures::complete($user, 'counter-strafe')->assertNotFound();
    LearningFixtures::enroll($user)->assertNotFound();
    LearningFixtures::progress($user)->assertNotFound();
    expect(LessonProgress::query()->count())->toBe(1)->and(Enrollment::query()->count())->toBe(1);
});

it('does not change any catalog table', function () {
    LearningFixtures::course();
    $tables = ['courses', 'course_modules', 'lessons', 'quizzes', 'academy_media'];
    $before = collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->toArray()])->all();

    $sql = LearningFixtures::sql(function () {
        $user = User::factory()->create();
        LearningFixtures::complete($user, 'peek');
        LearningFixtures::complete($user, 'mira');
    });

    expect(collect($tables)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->get()->toArray()])->all())->toEqual($before)
        ->and(array_filter($sql['writes'], fn (string $query) => preg_match('/"(courses|course_modules|lessons|quizzes|quiz_\w+|academy_media)"/', $query) === 1))->toBe([]);
});

it('locks the public content rows it relies on (FOR SHARE), in the import order', function () {
    LearningFixtures::course();

    $sql = LearningFixtures::sql(fn () => LearningFixtures::complete(User::factory()->create(), 'peek')->assertOk());

    $locks = array_values(array_filter($sql['queries'], fn (string $query) => str_contains($query, 'for share')));
    expect($locks)->toHaveCount(3)
        ->and($locks[0])->toContain('from "courses"')
        ->and($locks[1])->toContain('from "course_modules"')
        ->and($locks[2])->toContain('from "lessons"');
});
