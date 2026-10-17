<?php

namespace App\Modules\Learning\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Http\Resources\CourseProgressResource;
use App\Modules\Learning\Queries\ProgressQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/v1/academy/courses/{course}/progress. Só o progresso do próprio jogador (R13).
 * Curso não público: 404 (o histórico de arquivados fica em /me/enrollments, R13a).
 */
class CourseProgressController
{
    public function __invoke(Request $request, string $course, ProgressQuery $query): CourseProgressResource
    {
        /** @var User $user */
        $user = $request->user();

        $found = $query->publicCourse($course) ?? abort(404);
        $progress = $query->forCourse($user, $found);
        if ($progress->enrollment !== null) {
            Gate::authorize('view', $progress->enrollment);
        }

        return new CourseProgressResource($progress);
    }
}
