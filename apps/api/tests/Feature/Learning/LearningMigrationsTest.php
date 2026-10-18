<?php

use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Enums\LessonProgressStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\LearningFixtures;

/*
| Integridade dos dados do Learning no PostgreSQL (docs/database/academy-learning.md, ADR 0017).
*/

const LEARNING_TABLES = ['enrollments', 'lesson_progress'];

it('rolls back and re-applies the learning migrations without touching the catalog', function () {
    // As tentativas de quiz (2.5) vêm depois e são desfeitas junto.
    $this->artisan('migrate:rollback', ['--step' => 4])->assertSuccessful();
    foreach (LEARNING_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('lessons'))->toBeTrue()->and(Schema::hasTable('users'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    foreach (LEARNING_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('creates the documented indexes and delete rules', function () {
    $indexes = DB::table('pg_indexes')->whereIn('tablename', LEARNING_TABLES)->pluck('indexname')->all();
    expect($indexes)->toContain(
        'enrollments_user_id_course_id_unique',
        'enrollments_user_id_last_activity_at_index',
        'enrollments_course_id_index',
        'lesson_progress_user_id_lesson_id_unique',
        'lesson_progress_user_id_course_id_index',
    );

    // a = CASCADE (dado do jogador → users), r = RESTRICT (dado do jogador → conteúdo).
    $rules = DB::table('pg_constraint')->where('contype', 'f')->whereIn('conrelid', fn ($query) => $query
        ->selectRaw('oid')->from('pg_class')->whereIn('relname', LEARNING_TABLES))
        ->pluck('confdeltype', 'conname')->all();
    expect($rules)->toEqual([
        'enrollments_user_id_foreign' => 'c',
        'enrollments_course_id_foreign' => 'r',
        'lesson_progress_user_id_foreign' => 'c',
        'lesson_progress_course_id_foreign' => 'r',
        'lesson_progress_lesson_id_course_id_foreign' => 'r',
    ]);
});

it('allows one enrollment per player and course', function () {
    $enrollment = Enrollment::factory()->create();

    LearningFixtures::violates('enrollments_user_id_course_id_unique', fn () => Enrollment::factory()->create([
        'user_id' => $enrollment->user_id,
        'course_id' => $enrollment->course_id,
    ]));
    LearningFixtures::violates('enrollments_completed_at_check', fn () => Enrollment::factory()->create([
        'enrolled_at' => now(),
        'completed_at' => now()->subDay(),
    ]));
});

it('keeps lesson progress unique, coherent with the lesson course and with valid states', function () {
    [, , $lessons] = LearningFixtures::course();
    $other = Course::factory()->published()->create();
    $progress = LessonProgress::factory()->create(['lesson_id' => $lessons['peek']->id]);

    LearningFixtures::violates('lesson_progress_user_id_lesson_id_unique', fn () => LessonProgress::factory()->create([
        'user_id' => $progress->user_id,
        'lesson_id' => $lessons['peek']->id,
    ]));
    // course_id de outro curso: a FK composta recusa.
    LearningFixtures::violates('lesson_progress_lesson_id_course_id_foreign', fn () => LessonProgress::factory()->create([
        'lesson_id' => $lessons['mira']->id,
        'course_id' => $other->id,
    ]));
    LearningFixtures::violates('lesson_progress_status_check', fn () => DB::table('lesson_progress')
        ->where('id', $progress->id)->update(['status' => 'skipped', 'completed_at' => null, 'completed_content_hash' => null]));
    LearningFixtures::violates('lesson_progress_completed_at_check', fn () => LessonProgress::factory()->create([
        'lesson_id' => $lessons['mira']->id,
        'completed_at' => null,
    ]));
    LearningFixtures::violates('lesson_progress_completed_hash_check', fn () => LessonProgress::factory()->create([
        'lesson_id' => $lessons['mira']->id,
        'completed_content_hash' => 'abc',
    ]));
    LearningFixtures::violates('lesson_progress_completed_hash_check', fn () => LessonProgress::factory()->create([
        'lesson_id' => $lessons['mira']->id,
        'status' => LessonProgressStatus::InProgress,
        'completed_at' => null,
    ]));
});

it('deletes the player history with the account and never lets content with history be deleted', function () {
    [$course, , $lessons] = LearningFixtures::course();
    $user = User::factory()->create();
    Enrollment::factory()->create(['user_id' => $user->id, 'course_id' => $course->id]);
    LessonProgress::factory()->create(['user_id' => $user->id, 'lesson_id' => $lessons['peek']->id]);

    LearningFixtures::violates('lesson_progress_lesson_id_course_id_foreign', fn () => Lesson::query()->whereKey($lessons['peek']->id)->delete());
    LearningFixtures::violates('enrollments_course_id_foreign', function () use ($course) {
        DB::table('lesson_progress')->delete();
        DB::table('lessons')->delete();
        DB::table('course_modules')->delete();
        Course::query()->whereKey($course->id)->delete();
    });

    $user->delete();
    expect(Enrollment::query()->count())->toBe(0)->and(LessonProgress::query()->count())->toBe(0)
        ->and(Lesson::query()->count())->toBe(3);
});
