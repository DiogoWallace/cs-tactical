<?php

namespace App\Modules\Learning\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Actions\CompleteLesson;
use App\Modules\Learning\Http\Resources\CourseProgressResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/v1/academy/courses/{course}/lessons/{lesson}/completion (R8, R9). Sem corpo;
 * idempotente, com auto-matrícula. 200 com o progresso do curso. Aula não pública, de outro curso
 * ou inexistente: 404, sem revelar qual caso.
 */
class LessonCompletionController
{
    public function __invoke(Request $request, string $course, string $lesson, CompleteLesson $complete): CourseProgressResource
    {
        /** @var User $user */
        $user = $request->user();

        $progress = $complete->handle($user, $course, $lesson) ?? abort(404);
        if ($progress->enrollment !== null) {
            Gate::authorize('view', $progress->enrollment);
        }

        return new CourseProgressResource($progress);
    }
}
