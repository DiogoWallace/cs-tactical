<?php

namespace App\Modules\Academy\Http\Controllers;

use App\Modules\Academy\Enums\CourseLevel;
use App\Modules\Academy\Http\EditorialAccess;
use App\Modules\Academy\Http\Resources\CourseCollection;
use App\Modules\Academy\Http\Resources\CourseResource;
use App\Modules\Academy\Queries\CatalogQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catálogo de cursos (docs/api/academy-v1.md). Consultas só pelo CatalogQuery (ADR 0015).
 */
class CourseController
{
    /** Teto de `page`: evita OFFSET gigante; muito além de qualquer catálogo real. */
    private const MAX_PAGE = 10000;

    public function __construct(private readonly CatalogQuery $catalog) {}

    /** GET /api/v1/academy/courses?level=&page=&include_unpublished= */
    public function index(Request $request): CourseCollection
    {
        $validated = $request->validate([
            'level' => ['sometimes', 'string', Rule::enum(CourseLevel::class)],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ]);
        $level = isset($validated['level']) ? CourseLevel::from($validated['level']) : null;

        $courses = $this->catalog->courses($level, EditorialAccess::requested($request))
            // Links relativos à origem: atrás do proxy do Next o Host é o endereço interno da API.
            ->withPath(route('v1.academy.courses.index', absolute: false))
            ->appends(array_intersect_key($request->query(), array_flip(['level', 'include_unpublished'])));

        return new CourseCollection($courses);
    }

    /** GET /api/v1/academy/courses/{course}: não público (ou inexistente) responde 404. */
    public function show(Request $request, string $course): CourseResource
    {
        $found = $this->catalog->course($course, EditorialAccess::requested($request)) ?? abort(404);

        return new CourseResource($found);
    }
}
