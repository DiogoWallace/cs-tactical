<?php

namespace App\Modules\Learning\Http\Controllers;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Actions\EnrollInCourse;
use App\Modules\Learning\Http\Resources\EnrollmentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/v1/academy/courses/{course}/enrollment (R7). Sem corpo; o jogador é sempre o da
 * sessão. 201 na primeira matrícula, 200 se já existia. Curso não público: 404.
 */
class EnrollmentController
{
    public function __invoke(Request $request, string $course, EnrollInCourse $enroll): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        [$enrollment, $created] = $enroll->handle($user, $course) ?? abort(404);
        Gate::authorize('view', $enrollment);

        return (new EnrollmentResource($enrollment))->response()->setStatusCode($created ? 201 : 200);
    }
}
