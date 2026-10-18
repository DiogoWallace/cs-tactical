<?php

namespace App\Modules\Learning\Support;

use App\Modules\Academy\Contracts\QuizUsage;
use App\Modules\Academy\Models\Quiz;
use App\Modules\Learning\Models\QuizAttempt;

/**
 * Implementação real do contrato da Academy (etapa 2.5): um quiz "tem tentativas" se qualquer
 * jogador abriu ou enviou uma. Com tentativas, a importação aposenta perguntas e opções em vez de
 * apagá-las (ADR 0018, item 8).
 */
final class AttemptsQuizUsage implements QuizUsage
{
    public function hasAttempts(Quiz $quiz): bool
    {
        return QuizAttempt::query()->where('quiz_id', $quiz->id)->exists();
    }
}
