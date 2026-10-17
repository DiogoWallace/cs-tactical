<?php

namespace App\Modules\Academy\Import\Draft;

final readonly class OptionDraft
{
    public function __construct(
        public string $key,
        public string $text,
        public bool $correct,
        public int $position,
    ) {}
}
