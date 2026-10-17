<?php

namespace App\Modules\Academy\Enums;

/**
 * Tipo de pergunta de quiz (ADR 0018). `single_choice` tem exatamente uma opção correta;
 * `multiple_choice`, ao menos uma. Valores também em `quiz_questions_kind_check`.
 */
enum QuestionKind: string
{
    case SingleChoice = 'single_choice';
    case MultipleChoice = 'multiple_choice';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $kind) => $kind->value, self::cases());
    }
}
