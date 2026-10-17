<?php

namespace App\Modules\Academy\Import\Draft;

use App\Modules\Academy\Enums\PublicationStatus;

/** Aula validada: conteúdo canônico (LessonContentSchema) e o hash dele. */
final readonly class LessonDraft
{
    /** @param  array{version: int, blocks: list<array{type: string, data: array<string, mixed>}>}  $content */
    public function __construct(
        public string $location,
        public string $key,
        public string $slug,
        public string $title,
        public ?string $summary,
        public PublicationStatus $status,
        public int $position,
        public ?int $estimatedMinutes,
        public array $content,
        public string $contentHash,
        public ?QuizDraft $quiz,
    ) {}
}
