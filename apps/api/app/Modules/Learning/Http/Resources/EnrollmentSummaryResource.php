<?php

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Learning\Queries\EnrollmentSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Uma matrícula em GET /api/v1/me/enrollments. Curso arquivado: título e status para o histórico
 * (R13a), sem total, percentual nem próxima aula.
 *
 * @property EnrollmentSummary $resource
 */
class EnrollmentSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $summary = $this->resource;
        $enrollment = $summary->enrollment;

        return [
            'course' => [
                'slug' => $enrollment->course->slug,
                'title' => $enrollment->course->title,
                'status' => $enrollment->course->status->value,
            ],
            'enrolled_at' => $enrollment->enrolled_at->toIso8601ZuluString(),
            'completed_at' => $enrollment->completed_at?->toIso8601ZuluString(),
            'last_activity_at' => $enrollment->last_activity_at->toIso8601ZuluString(),
            'progress' => [
                'completed_lessons' => $summary->completedLessons,
                'total_lessons' => $summary->totalLessons,
                'percent' => $summary->percent(),
            ],
            'next_lesson' => CourseProgressResource::lessonLink($summary->nextLesson),
        ];
    }
}
