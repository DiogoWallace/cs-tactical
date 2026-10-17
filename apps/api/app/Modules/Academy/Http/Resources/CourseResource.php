<?php

namespace App\Modules\Academy\Http\Resources;

use App\Modules\Academy\Http\EditorialAccess;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Curso com módulos e aulas, na ordem de `position` (CatalogQuery::course). Sem progresso: a
 * resposta é igual para todos (docs/api/academy-v1.md). `status` só no modo editorial.
 *
 * @mixin Course
 */
class CourseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $editorial = EditorialAccess::requested($request);

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'level' => $this->level->value,
            ...($editorial ? ['status' => $this->status->value] : []),
            'cover_image_url' => $this->coverMedia === null ? null : MediaUrl::of($this->coverMedia),
            'modules' => $this->modules->map(fn (CourseModule $module) => [
                'slug' => $module->slug,
                'title' => $module->title,
                ...($editorial ? ['status' => $module->status->value] : []),
                'lessons' => $module->lessons->map(fn (Lesson $lesson) => [
                    'slug' => $lesson->slug,
                    'title' => $lesson->title,
                    ...($editorial ? ['status' => $lesson->status->value] : []),
                    'estimated_minutes' => $lesson->estimated_minutes,
                    'has_quiz' => (bool) $lesson->getAttribute('has_quiz'),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
