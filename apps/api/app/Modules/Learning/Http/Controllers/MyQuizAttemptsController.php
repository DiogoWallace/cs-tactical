<?php

namespace App\Modules\Learning\Http\Controllers;

use App\Modules\Academy\Models\Quiz;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\QuizAttempt;
use App\Modules\Learning\Quizzes\QuizAttemptPresenter;
use App\Modules\Learning\Quizzes\QuizStandingQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/me/quiz-attempts?quiz={key}: tentativas do jogador da sessão naquele quiz, mais
 * recentes primeiro, em resumo (R13a: inclui quiz de aula arquivada). Quiz desconhecido: lista
 * vazia, sem revelar se existe. Detalhes por tentativa em GET /academy/quiz-attempts/{attempt}.
 */
class MyQuizAttemptsController
{
    public function __invoke(Request $request, QuizAttemptPresenter $presenter, QuizStandingQuery $standings): JsonResponse
    {
        $validated = $request->validate(['quiz' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/']]);
        /** @var User $user */
        $user = $request->user();

        $quiz = Quiz::query()->where('key', $validated['quiz'])->first();
        if ($quiz === null) {
            return response()->json(['data' => []]);
        }

        $attempts = QuizAttempt::query()
            ->where('user_id', $user->getKey())
            ->where('quiz_id', $quiz->id)
            ->orderByDesc('attempt_number')
            ->get();
        $standing = $standings->for((string) $user->getKey(), $quiz);

        return response()->json(['data' => $attempts->map(function (QuizAttempt $attempt) use ($presenter, $standing) {
            Gate::authorize('view', $attempt);

            return $presenter->summary($attempt, $standing);
        })->values()->all()]);
    }
}
