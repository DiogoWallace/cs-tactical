<?php

namespace App\Modules\Academy\Import\Draft;

use App\Modules\Academy\Enums\CourseLevel;
use App\Modules\Academy\Enums\PublicationStatus;

/** Curso validado a partir de `course.yaml`, com os módulos do diretório. */
final readonly class CourseDraft
{
    /** @param  list<ModuleDraft>  $modules */
    public function __construct(
        public string $location,
        public string $key,
        public string $slug,
        public string $title,
        public string $summary,
        public CourseLevel $level,
        public PublicationStatus $status,
        public int $position,
        public ?string $coverSha,
        public array $modules,
    ) {}
}
