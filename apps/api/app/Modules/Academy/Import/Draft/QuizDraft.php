<?php

namespace App\Modules\Academy\Import\Draft;

use App\Modules\Academy\Content\CanonicalJson;

/** Quiz validado de uma aula (ADR 0018). */
final readonly class QuizDraft
{
    /** @param  list<QuestionDraft>  $questions */
    public function __construct(
        public string $location,
        public string $key,
        public string $title,
        public int $maxAttempts,
        public int $passPercent,
        public array $questions,
    ) {}

    /**
     * Hash do que afeta a correção (regras, perguntas, opções, gabarito, ordem). Mudou, a
     * `revision` sobe (ADR 0018). O título não entra: não muda a correção.
     *
     * @param  list<QuestionDraft>  $questions
     */
    public static function gradingHash(int $maxAttempts, int $passPercent, array $questions): string
    {
        return CanonicalJson::hash([
            'max_attempts' => $maxAttempts,
            'pass_percent' => $passPercent,
            'questions' => array_map(fn (QuestionDraft $question) => [
                'key' => $question->key,
                'kind' => $question->kind->value,
                'prompt' => $question->prompt,
                'explanation' => $question->explanation,
                'options' => array_map(fn (OptionDraft $option) => [
                    'key' => $option->key,
                    'text' => $option->text,
                    'correct' => $option->correct,
                ], $question->options),
            ], $questions),
        ]);
    }

    public function hash(): string
    {
        return self::gradingHash($this->maxAttempts, $this->passPercent, $this->questions);
    }
}
