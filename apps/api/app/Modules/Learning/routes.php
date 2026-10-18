<?php

use App\Modules\Learning\Http\Controllers\CourseProgressController;
use App\Modules\Learning\Http\Controllers\EnrollmentController;
use App\Modules\Learning\Http\Controllers\LessonCompletionController;
use App\Modules\Learning\Http\Controllers\MyEnrollmentsController;
use Illuminate\Support\Facades\Route;

/*
| Learning (etapa 2.4, docs/api/academy-v1.md): matrícula, conclusão e progresso do jogador da
| sessão. Nenhuma rota recebe id de usuário nem de matrícula (R13); curso e aula são slugs, fora do
| formato respondem 404 no roteador. Escritas: `learning-write`; leituras: `api`.
*/
$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

Route::middleware('auth:sanctum')->group(function () use ($slug) {
    Route::get('/me/enrollments', MyEnrollmentsController::class)
        ->middleware('throttle:api')
        ->name('me.enrollments.index');

    Route::prefix('academy/courses/{course}')->name('learning.')->where(['course' => $slug])->group(function () use ($slug) {
        Route::put('/enrollment', EnrollmentController::class)
            ->middleware('throttle:learning-write')
            ->name('enrollment.update');

        Route::get('/progress', CourseProgressController::class)
            ->middleware('throttle:api')
            ->name('progress.show');

        Route::put('/lessons/{lesson}/completion', LessonCompletionController::class)
            ->where('lesson', $slug)
            ->middleware('throttle:learning-write')
            ->name('lessons.completion.update');
    });
});
