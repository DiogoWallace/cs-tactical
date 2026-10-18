<?php

use App\Modules\Learning\Http\Controllers\CourseProgressController;
use App\Modules\Learning\Http\Controllers\EnrollmentController;
use App\Modules\Learning\Http\Controllers\LessonCompletionController;
use App\Modules\Learning\Http\Controllers\MyEnrollmentsController;
use App\Modules\Learning\Http\Controllers\MyQuizAttemptsController;
use App\Modules\Learning\Http\Controllers\QuizAttemptController;
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

    /*
    | Quizzes (etapa 2.5, ADR 0018). `{quiz}` é a key; `{attempt}` é UUID. Iniciar e enviar:
    | `quiz-attempt` (10/min, também dificulta adivinhar o gabarito); leituras: `api`.
    */
    Route::post('/academy/quizzes/{quiz}/attempts', [QuizAttemptController::class, 'store'])
        ->where('quiz', $slug)
        ->middleware('throttle:quiz-attempt')
        ->name('learning.quiz-attempts.store');

    Route::post('/academy/quiz-attempts/{attempt}/submission', [QuizAttemptController::class, 'submit'])
        ->whereUuid('attempt')
        ->middleware('throttle:quiz-attempt')
        ->name('learning.quiz-attempts.submit');

    Route::get('/academy/quiz-attempts/{attempt}', [QuizAttemptController::class, 'show'])
        ->whereUuid('attempt')
        ->middleware('throttle:api')
        ->name('learning.quiz-attempts.show');

    Route::get('/me/quiz-attempts', MyQuizAttemptsController::class)
        ->middleware('throttle:api')
        ->name('me.quiz-attempts.index');
});
