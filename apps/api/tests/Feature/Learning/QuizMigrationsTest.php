<?php

use App\Modules\Academy\Models\Quiz;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\QuizAttempt;
use App\Modules\Learning\Models\QuizAttemptAnswer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\LearningFixtures;

/*
| Integridade das tentativas de quiz no PostgreSQL (etapa 2.5, docs/database/academy-learning.md).
*/

const QUIZ_ATTEMPT_TABLES = ['quiz_attempts', 'quiz_attempt_answers'];

it('rolls back and re-applies the quiz attempt migrations', function () {
    // A da Steam (M2) é a mais recente e é desfeita junto.
    $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();
    foreach (QUIZ_ATTEMPT_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
    expect(Schema::hasTable('enrollments'))->toBeTrue()->and(Schema::hasTable('quizzes'))->toBeTrue();

    $this->artisan('migrate')->assertSuccessful();
    foreach (QUIZ_ATTEMPT_TABLES as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('creates the documented indexes and delete rules', function () {
    expect(DB::table('pg_indexes')->whereIn('tablename', QUIZ_ATTEMPT_TABLES)->pluck('indexname')->all())->toContain(
        'quiz_attempts_user_id_quiz_id_attempt_number_unique',
        'quiz_attempts_one_open_unique',
        'quiz_attempts_quiz_id_index',
        'quiz_attempt_answers_attempt_id_question_id_unique',
    );

    $rules = DB::table('pg_constraint')->where('contype', 'f')->whereIn('conrelid', fn ($query) => $query
        ->selectRaw('oid')->from('pg_class')->whereIn('relname', QUIZ_ATTEMPT_TABLES))
        ->pluck('confdeltype', 'conname')->all();
    expect($rules)->toEqual([
        'quiz_attempts_user_id_foreign' => 'c',
        'quiz_attempts_quiz_id_foreign' => 'r',
        'quiz_attempt_answers_attempt_id_foreign' => 'c',
        'quiz_attempt_answers_question_id_foreign' => 'r',
    ]);
});

it('allows one open attempt per player and quiz and unique attempt numbers', function () {
    $attempt = QuizAttempt::factory()->create();

    LearningFixtures::violates('quiz_attempts_one_open_unique', fn () => QuizAttempt::factory()->create([
        'user_id' => $attempt->user_id, 'quiz_id' => $attempt->quiz_id, 'attempt_number' => 2,
    ]));
    LearningFixtures::violates('quiz_attempts_user_id_quiz_id_attempt_number_unique', fn () => QuizAttempt::factory()->submitted()->create([
        'user_id' => $attempt->user_id, 'quiz_id' => $attempt->quiz_id, 'attempt_number' => 1,
    ]));

    // Enviadas podem ser várias.
    QuizAttempt::factory()->submitted()->create(['user_id' => $attempt->user_id, 'quiz_id' => $attempt->quiz_id, 'attempt_number' => 2]);
    QuizAttempt::factory()->submitted()->create(['user_id' => $attempt->user_id, 'quiz_id' => $attempt->quiz_id, 'attempt_number' => 3]);
    expect(QuizAttempt::query()->count())->toBe(3);
});

it('keeps status, grading fields and score coherent', function () {
    LearningFixtures::violates('quiz_attempts_status_check', fn () => DB::table('quiz_attempts')
        ->where('id', QuizAttempt::factory()->create()->id)->update(['status' => 'abandoned']));
    LearningFixtures::violates('quiz_attempts_submitted_check', fn () => QuizAttempt::factory()->create(['submitted_at' => now()]));
    LearningFixtures::violates('quiz_attempts_submitted_check', fn () => QuizAttempt::factory()->submitted()->create(['passed' => null]));
    LearningFixtures::violates('quiz_attempts_score_check', fn () => QuizAttempt::factory()->submitted()->create(['score_percent' => 101]));
    LearningFixtures::violates('quiz_attempts_score_check', fn () => QuizAttempt::factory()->submitted(correct: 3, total: 2)->create(['score_percent' => 100]));
    LearningFixtures::violates('quiz_attempts_attempt_number_check', fn () => QuizAttempt::factory()->create(['attempt_number' => 0]));
    LearningFixtures::violates('quiz_attempts_snapshot_check', fn () => QuizAttempt::factory()->create(['snapshot' => ['questions' => 'x']]));
});

it('requires selected options and one answer per question', function () {
    [, , $lessons] = LearningFixtures::course();
    [$quiz, $ids] = LearningFixtures::quiz($lessons['peek']);
    $attempt = QuizAttempt::factory()->submitted()->create(['quiz_id' => $quiz->id]);
    $answer = fn (array $options) => (new QuizAttemptAnswer)->forceFill([
        'id' => (string) Str::uuid7(), 'attempt_id' => $attempt->id, 'question_id' => $ids['tecla'],
        'selected_option_ids' => $options, 'is_correct' => false,
    ])->save();

    LearningFixtures::violates('quiz_attempt_answers_selected_check', fn () => $answer([]));
    $answer([$ids['tecla.a']]);
    LearningFixtures::violates('quiz_attempt_answers_attempt_id_question_id_unique', fn () => $answer([$ids['tecla.d']]));
    expect(QuizAttemptAnswer::query()->sole()->selected_option_ids)->toBe([$ids['tecla.a']]);
});

it('deletes attempts with the account and never lets a quiz with attempts be deleted', function () {
    $user = User::factory()->create();
    $attempt = QuizAttempt::factory()->submitted()->create(['user_id' => $user->id]);

    LearningFixtures::violates('quiz_attempts_quiz_id_foreign', fn () => Quiz::query()->whereKey($attempt->quiz_id)->delete());

    $user->delete();
    expect(QuizAttempt::query()->count())->toBe(0)->and(Quiz::query()->count())->toBe(1);
});
