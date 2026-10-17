<?php

namespace App\Modules\Academy\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Página do catálogo: `{ data, links, meta }` (docs/api/convencoes.md). Links relativos à
 * origem (definidos pelo controller) e `meta` só com os números da paginação.
 */
class CourseCollection extends ResourceCollection
{
    public $collects = CourseSummaryResource::class;

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array{links: array<string, mixed>, meta: array<string, mixed>}  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'links' => $default['links'],
            'meta' => [
                'current_page' => $paginated['current_page'],
                'last_page' => $paginated['last_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
            ],
        ];
    }
}
