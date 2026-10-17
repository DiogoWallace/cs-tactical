<?php

namespace App\Modules\Academy\Contracts;

use App\Modules\Academy\Models\Quiz;

/**
 * Diz se um quiz já teve tentativas. A Academy não conhece o módulo Learning (ADR 0015); o
 * Learning registra a implementação real na etapa 2.4. Até lá, nenhum quiz tem tentativa.
 *
 * Usado pela importação: perguntas e opções de quiz com tentativa são aposentadas, não
 * apagadas (ADR 0018, item 8).
 */
interface QuizUsage
{
    public function hasAttempts(Quiz $quiz): bool;
}
