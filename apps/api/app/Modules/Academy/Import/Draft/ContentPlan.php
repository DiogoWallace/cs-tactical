<?php

namespace App\Modules\Academy\Import\Draft;

use App\Modules\Academy\Import\InspectedImage;

/** Todo o conteúdo validado de uma pasta, pronto para importar. */
final readonly class ContentPlan
{
    /**
     * @param  list<CourseDraft>  $courses
     * @param  array<string, InspectedImage>  $images  por sha256
     */
    public function __construct(
        public array $courses,
        public array $images,
    ) {}
}
