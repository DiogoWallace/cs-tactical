<?php

namespace App\Modules\Learning\Queries;

use Illuminate\Support\Carbon;

/** Uma aula pública do curso com o estado do jogador nela. */
final readonly class LessonProgressItem
{
    public function __construct(
        public string $slug,
        public string $title,
        public bool $completed,
        public ?Carbon $completedAt,
        /** O conteúdo mudou desde a conclusão (hash diferente, ADR 0017); a conclusão continua. */
        public bool $updatedSinceCompletion,
    ) {}
}
