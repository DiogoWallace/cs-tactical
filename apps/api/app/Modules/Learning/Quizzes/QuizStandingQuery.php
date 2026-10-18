<?php

namespace App\Modules\Learning\Quizzes;

use App\Modules\Academy\Models\Quiz;
use App\Modules\Learning\Enums\QuizAttemptStatus;
use App\Modules\Learning\Models\QuizAttempt;

/** Situação do jogador num quiz: uma consulta agregada. */
final class QuizStandingQuery
{
    public function for(string $userId, Quiz $quiz): QuizStanding
    {
        $row = QuizAttempt::query()
            ->where('user_id', $userId)
            ->where('quiz_id', $quiz->id)
            ->selectRaw('count(*) as total')
            ->selectRaw('count(*) filter (where status = ?) as submitted', [QuizAttemptStatus::Submitted->value])
            ->selectRaw('coalesce(bool_or(passed), false) as passed')
            ->selectRaw('max(score_percent) as best')
            ->toBase()
            ->first();

        return new QuizStanding(
            maxAttempts: $quiz->max_attempts,
            totalAttempts: (int) ($row->total ?? 0),
            submittedAttempts: (int) ($row->submitted ?? 0),
            passed: (bool) ($row->passed ?? false),
            bestScore: ($row->best ?? null) === null ? null : (string) $row->best,
        );
    }
}
