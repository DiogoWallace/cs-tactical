<?php

namespace App\Modules\Learning\Queries;

use App\Modules\Learning\Models\Enrollment;

/**
 * Uma matrícula em "Meu progresso". Curso público: progresso atual. Curso que deixou de ser
 * público: só o histórico (`totalLessons` e `nextLesson` nulos; R13a).
 */
final readonly class EnrollmentSummary
{
    public function __construct(
        public Enrollment $enrollment,
        public int $completedLessons,
        public ?int $totalLessons,
        public ?LessonProgressItem $nextLesson,
    ) {}

    public function percent(): ?float
    {
        return $this->totalLessons === null ? null : CourseProgress::percentOf($this->completedLessons, $this->totalLessons);
    }
}
