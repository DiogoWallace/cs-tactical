<?php

namespace App\Modules\Academy\Import\Draft;

use App\Modules\Academy\Enums\PublicationStatus;

/** Módulo validado a partir de `module.yaml`, com as aulas do diretório. */
final readonly class ModuleDraft
{
    /** @param  list<LessonDraft>  $lessons */
    public function __construct(
        public string $location,
        public string $key,
        public string $slug,
        public string $title,
        public ?string $summary,
        public PublicationStatus $status,
        public int $position,
        public array $lessons,
    ) {}
}
