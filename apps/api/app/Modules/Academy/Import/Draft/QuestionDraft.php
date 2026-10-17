<?php

namespace App\Modules\Academy\Import\Draft;

use App\Modules\Academy\Enums\QuestionKind;

final readonly class QuestionDraft
{
    /** @param  list<OptionDraft>  $options */
    public function __construct(
        public string $key,
        public QuestionKind $kind,
        public string $prompt,
        public ?string $explanation,
        public int $position,
        public array $options,
    ) {}
}
