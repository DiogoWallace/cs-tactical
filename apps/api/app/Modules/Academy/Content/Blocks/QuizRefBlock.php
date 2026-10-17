<?php

namespace App\Modules\Academy\Content\Blocks;

use App\Modules\Academy\Content\BlockContext;
use App\Modules\Academy\Content\Fields;
use App\Modules\Academy\Content\ValidationErrors;

/**
 * `quiz_ref`: posição do quiz da **própria** aula no conteúdo. Só a `key` vai para o banco; a API
 * resolve o resto, sem perguntas nem gabarito (ADR 0018).
 */
final class QuizRefBlock implements BlockSchema
{
    public function type(): string
    {
        return 'quiz_ref';
    }

    public function normalize(array $fields, string $location, BlockContext $context, ValidationErrors $errors): ?array
    {
        $before = $errors->count();
        Fields::rejectUnknown($fields, ['quiz'], $location, $errors);
        $quiz = Fields::key($fields, 'quiz', $location, $errors);

        if ($quiz !== null && $quiz !== $context->lessonQuizKey()) {
            $errors->add(Fields::at($location, 'quiz'), 'precisa ser a key do quiz declarado nesta aula');
        }

        return $errors->count() === $before ? ['quiz' => $quiz] : null;
    }
}
