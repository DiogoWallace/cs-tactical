<?php

namespace App\Modules\Learning\Queries;

use Illuminate\Support\Carbon;

/** Uma aula pública do curso com o estado do jogador nela. */
final readonly class LessonProgressItem
{
    /**
     * @param  array{attempts_used: int, best_score_percent: float|null, passed: bool}|null  $quiz  resumo do quiz ativo da aula
     */
    public function __construct(
        public string $slug,
        public string $title,
        public bool $completed,
        public ?Carbon $completedAt,
        /** O conteúdo mudou desde a conclusão (hash diferente, ADR 0017); a conclusão continua. */
        public bool $updatedSinceCompletion,
        /** Quiz iniciado e aula ainda não concluída (R10). */
        public bool $inProgress = false,
        public ?array $quiz = null,
    ) {}

    public function status(): string
    {
        return match (true) {
            $this->completed => 'completed',
            $this->inProgress => 'in_progress',
            default => 'not_started',
        };
    }
}
