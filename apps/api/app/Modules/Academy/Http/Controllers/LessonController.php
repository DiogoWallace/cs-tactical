<?php

namespace App\Modules\Academy\Http\Controllers;

use App\Modules\Academy\Http\EditorialAccess;
use App\Modules\Academy\Http\Resources\LessonResource;
use App\Modules\Academy\Queries\CatalogQuery;
use Illuminate\Http\Request;

/**
 * GET /api/v1/academy/courses/{course}/lessons/{lesson}
 *
 * Aula pública = aula, módulo e curso publicados (R2). Qualquer outro caso, inclusive aula de
 * outro curso ou arquivada (P1), responde 404 sem revelar que existe. Ler não grava nada (R10).
 */
class LessonController
{
    public function __construct(private readonly CatalogQuery $catalog) {}

    public function __invoke(Request $request, string $course, string $lesson): LessonResource
    {
        $page = $this->catalog->lesson($course, $lesson, EditorialAccess::requested($request)) ?? abort(404);

        return new LessonResource($page);
    }
}
