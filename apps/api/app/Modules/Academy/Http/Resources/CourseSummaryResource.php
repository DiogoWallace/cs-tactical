<?php

namespace App\Modules\Academy\Http\Resources;

use App\Modules\Academy\Http\EditorialAccess;
use App\Modules\Academy\Models\Course;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Curso na listagem do catálogo. Contagens e minutos vêm da consulta (CatalogQuery::courses),
 * só com módulos e aulas públicos fora do modo editorial. `status` só no modo editorial.
 *
 * Lista fechada de campos: `id`, `key`, datas e colunas futuras não saem.
 *
 * @mixin Course
 */
class CourseSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $minutes = $this->resource->getAttribute('estimated_minutes');

        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'summary' => $this->summary,
            'level' => $this->level->value,
            ...(EditorialAccess::requested($request) ? ['status' => $this->status->value] : []),
            'cover_image_url' => $this->coverMedia === null ? null : MediaUrl::of($this->coverMedia),
            'module_count' => (int) $this->resource->getAttribute('module_count'),
            'lesson_count' => (int) $this->resource->getAttribute('lesson_count'),
            'estimated_minutes' => $minutes === null ? null : (int) $minutes,
        ];
    }
}
