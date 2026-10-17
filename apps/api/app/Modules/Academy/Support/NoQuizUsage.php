<?php

namespace App\Modules\Academy\Support;

use App\Modules\Academy\Contracts\QuizUsage;
use App\Modules\Academy\Models\Quiz;

/** Implementação até existir o módulo Learning (etapa 2.4): nenhum quiz tem tentativa. */
final class NoQuizUsage implements QuizUsage
{
    public function hasAttempts(Quiz $quiz): bool
    {
        return false;
    }
}
