<?php

namespace Database\Factories\Learning;

use App\Modules\Academy\Models\Quiz;
use App\Modules\Learning\Enums\QuizAttemptStatus;
use App\Modules\Learning\Models\QuizAttempt;
use Database\Factories\Academy\QuizFactory;
use Database\Factories\Identity\UserFactory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tentativa aberta com um snapshot mínimo (uma pergunta de escolha única). Para testes de
 * integridade; os fluxos reais usam StartQuizAttempt.
 *
 * @extends Factory<QuizAttempt>
 */
class QuizAttemptFactory extends Factory
{
    protected $model = QuizAttempt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'quiz_id' => QuizFactory::new(),
            'attempt_number' => 1,
            'quiz_revision' => fn (array $attributes) => (int) (Quiz::query()->whereKey($attributes['quiz_id'])->value('revision') ?? 1),
            'status' => QuizAttemptStatus::InProgress,
            'snapshot' => ['pass_percent' => 70, 'questions' => []],
            'started_at' => now(),
        ];
    }

    public function submitted(int $correct = 1, int $total = 1, bool $passed = true): static
    {
        return $this->state([
            'status' => QuizAttemptStatus::Submitted,
            'submitted_at' => now(),
            'question_count' => $total,
            'correct_count' => $correct,
            'score_percent' => round($correct / $total * 100, 2),
            'passed' => $passed,
        ]);
    }
}
