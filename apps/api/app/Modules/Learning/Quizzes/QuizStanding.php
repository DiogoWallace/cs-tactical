<?php

namespace App\Modules\Learning\Quizzes;

/**
 * Situação do jogador num quiz, calculada no servidor a cada resposta (ADR 0018, itens 4, 5 e 11).
 *
 * - `attemptsRemaining`: tentativas abertas **e** enviadas contam para `max_attempts` (R16).
 * - `reviewAvailable` (R20): aprovado em alguma tentativa **ou** todas as tentativas enviadas. Uma
 *   última tentativa ainda aberta não libera o gabarito, que poderia ser usado nela.
 */
final readonly class QuizStanding
{
    public function __construct(
        public int $maxAttempts,
        public int $totalAttempts,
        public int $submittedAttempts,
        public bool $passed,
        public ?string $bestScore,
    ) {}

    public function attemptsRemaining(): int
    {
        return max(0, $this->maxAttempts - $this->totalAttempts);
    }

    public function reviewAvailable(): bool
    {
        return $this->passed || $this->submittedAttempts >= $this->maxAttempts;
    }
}
