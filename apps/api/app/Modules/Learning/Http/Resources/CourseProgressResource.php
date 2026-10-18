<?php

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Learning\Queries\CourseProgress;
use App\Modules\Learning\Queries\LessonProgressItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Progresso do jogador num curso (GET .../progress e PUT .../completion). Lista fechada: nenhum
 * id, nenhum dado de outro jogador; do quiz, só o resumo (tentativas, melhor nota, aprovado).
 *
 * Sem matrícula: `enrolled: false`, números nulos e `lessons` vazia (contrato).
 *
 * @property CourseProgress $resource
 */
class CourseProgressResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $progress = $this->resource;
        $enrollment = $progress->enrollment;

        if ($enrollment === null) {
            return [
                'enrolled' => false,
                'enrolled_at' => null,
                'completed_at' => null,
                'percent' => null,
                'completed_lessons' => null,
                'total_lessons' => null,
                'new_lessons_available' => false,
                'next_lesson' => null,
                'lessons' => [],
            ];
        }

        return [
            'enrolled' => true,
            'enrolled_at' => $enrollment->enrolled_at->toIso8601ZuluString(),
            'completed_at' => $enrollment->completed_at?->toIso8601ZuluString(),
            'percent' => $progress->percent(),
            'completed_lessons' => $progress->completedLessons,
            'total_lessons' => $progress->totalLessons,
            'new_lessons_available' => $progress->newLessonsAvailable(),
            'next_lesson' => self::lessonLink($progress->nextLesson),
            'lessons' => array_map(fn (LessonProgressItem $item) => [
                'slug' => $item->slug,
                'status' => $item->status(),
                'completed_at' => $item->completedAt?->toIso8601ZuluString(),
                'updated_since_completion' => $item->updatedSinceCompletion,
                // Resumo do quiz ativo da aula (2.5); null se a aula não tem quiz.
                'quiz' => $item->quiz,
            ], $progress->lessons),
        ];
    }

    /** @return array{slug: string, title: string}|null */
    public static function lessonLink(?LessonProgressItem $item): ?array
    {
        return $item === null ? null : ['slug' => $item->slug, 'title' => $item->title];
    }
}
