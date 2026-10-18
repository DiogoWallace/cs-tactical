<?php

namespace App\Modules\Learning\Http\Controllers;

use App\Modules\Academy\Models\Quiz;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Actions\StartQuizAttempt;
use App\Modules\Learning\Actions\SubmitQuizAttempt;
use App\Modules\Learning\Exceptions\QuizAttemptConflict;
use App\Modules\Learning\Models\QuizAttempt;
use App\Modules\Learning\Quizzes\QuizAttemptPresenter;
use App\Modules\Learning\Quizzes\QuizStandingQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Tentativas de quiz do jogador da sessão (docs/api/academy-v1.md#quizzes-autenticado):
 *
 * - POST /academy/quizzes/{quiz}/attempts: inicia (201) ou devolve a aberta (200);
 * - POST /academy/quiz-attempts/{attempt}/submission: envia e corrige (200);
 * - GET /academy/quiz-attempts/{attempt}: detalhe, também de aula arquivada (histórico).
 *
 * Tentativa de outro jogador ou inexistente: 404. Toda descrição de tentativa sai do
 * QuizAttemptPresenter, que decide o que do gabarito pode aparecer (R20–R20b).
 */
class QuizAttemptController
{
    public function __construct(
        private readonly QuizAttemptPresenter $presenter,
        private readonly QuizStandingQuery $standings,
    ) {}

    public function store(Request $request, string $quiz, StartQuizAttempt $start): JsonResponse
    {
        $user = $this->user($request);

        try {
            [$attempt, $created] = $start->handle($user, $quiz) ?? abort(404);
        } catch (QuizAttemptConflict $conflict) {
            return $this->conflict($conflict);
        }
        Gate::authorize('view', $attempt);

        return $this->respond($attempt, $created ? 201 : 200);
    }

    public function submit(Request $request, string $attempt, SubmitQuizAttempt $submit): JsonResponse
    {
        $user = $this->user($request);
        $validated = $request->validate([
            'answers' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'answers.*' => ['required', 'array:question_id,option_ids'],
            'answers.*.question_id' => ['required', 'uuid'],
            'answers.*.option_ids' => ['required', 'array', 'list', 'min:1', 'max:8'],
            'answers.*.option_ids.*' => ['required', 'uuid'],
        ]);

        try {
            $submitted = $submit->handle($user, $attempt, $validated['answers']) ?? abort(404);
        } catch (QuizAttemptConflict $conflict) {
            return $this->conflict($conflict);
        }
        Gate::authorize('submit', $submitted);

        return $this->respond($submitted, 200);
    }

    public function show(Request $request, string $attempt): JsonResponse
    {
        $found = QuizAttempt::query()
            ->whereKey($attempt)
            ->where('user_id', $this->user($request)->getKey())
            ->first() ?? abort(404);
        Gate::authorize('view', $found);

        return $this->respond($found, 200);
    }

    private function respond(QuizAttempt $attempt, int $status): JsonResponse
    {
        /** @var Quiz $quiz */
        $quiz = Quiz::query()->findOrFail($attempt->quiz_id);
        $standing = $this->standings->for($attempt->user_id, $quiz);
        $answers = $attempt->answers()->get()->keyBy('question_id');

        return response()->json(['data' => $this->presenter->detail($attempt, $standing, $answers)], $status);
    }

    private function conflict(QuizAttemptConflict $conflict): JsonResponse
    {
        return response()->json(['message' => $conflict->getMessage(), 'code' => $conflict->errorCode], 409);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
