<?php

use App\Modules\Academy\Http\Controllers\CourseController;
use App\Modules\Academy\Http\Controllers\LessonController;
use App\Modules\Academy\Http\Controllers\MediaController;
use Illuminate\Support\Facades\Route;

/*
| Catálogo público da Academy (etapa 2.2, docs/api/academy-v1.md). Sem `auth:sanctum`: estão na
| lista pública do RouteAuthorizationTest. Só conteúdo público (ADR 0015); rascunhos e
| arquivados apenas com `include_unpublished=true` e `manage-academy-content`.
|
| Slugs e nomes de arquivo fora do formato respondem 404 no próprio roteador.
*/
$slug = '[a-z0-9]+(?:-[a-z0-9]+)*';

Route::prefix('academy')->name('academy.')->group(function () use ($slug) {
    Route::middleware('throttle:academy-public')->group(function () use ($slug) {
        Route::get('/courses', [CourseController::class, 'index'])
            ->name('courses.index');

        Route::get('/courses/{course}', [CourseController::class, 'show'])
            ->where('course', $slug)
            ->name('courses.show');

        Route::get('/courses/{course}/lessons/{lesson}', LessonController::class)
            ->where(['course' => $slug, 'lesson' => $slug])
            ->name('lessons.show');
    });

    // Imagens do disco `academy` (ADR 0019), endereçadas pelo hash do conteúdo.
    Route::get('/media/{file}', MediaController::class)
        ->where('file', '[0-9a-f]{64}\.(?:png|jpg|webp|avif)')
        ->middleware('throttle:academy-media')
        ->name('media.show');
});
