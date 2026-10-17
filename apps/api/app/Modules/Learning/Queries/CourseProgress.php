<?php

namespace App\Modules\Learning\Queries;

use App\Modules\Learning\Models\Enrollment;

/**
 * Progresso de um jogador num curso público (R11, ADR 0017), calculado a cada leitura a partir
 * das aulas públicas de agora e das conclusões gravadas: nenhum percentual guardado no banco.
 *
 * - `percent` = concluídas ÷ públicas; aulas arquivadas saem do denominador.
 * - `newLessonsAvailable`: curso já concluído (`completed_at`, que não é apagado) com aulas
 *   publicadas depois.
 * - `nextLesson`: primeira aula pública não concluída, na ordem do curso (R10).
 */
final readonly class CourseProgress
{
    /** @param  list<LessonProgressItem>  $lessons */
    public function __construct(
        public ?Enrollment $enrollment,
        public int $completedLessons,
        public int $totalLessons,
        public array $lessons,
        public ?LessonProgressItem $nextLesson,
    ) {}

    public function enrolled(): bool
    {
        return $this->enrollment !== null;
    }

    public function percent(): float
    {
        return self::percentOf($this->completedLessons, $this->totalLessons);
    }

    public function newLessonsAvailable(): bool
    {
        return $this->enrollment?->completed_at !== null && $this->completedLessons < $this->totalLessons;
    }

    public static function percentOf(int $completed, int $total): float
    {
        return $total === 0 ? 0.0 : round($completed / $total * 100, 2);
    }
}
