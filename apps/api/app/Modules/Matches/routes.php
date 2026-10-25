<?php

use App\Modules\Matches\Http\Controllers\MatchUploadController;
use Illuminate\Support\Facades\Route;

/*
| Envio de demos (etapa M3, docs/training/mvp-m3-envio-de-demos.md). Só o próprio usuário: o envio
| é buscado pelo id **e** pelo dono (outro usuário recebe 404), e a MatchUploadPolicy confirma.
*/

$uuid = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

Route::prefix('matches/uploads')->name('matches.uploads.')->middleware('auth:sanctum')->group(function () use ($uuid) {
    Route::post('/', [MatchUploadController::class, 'store'])
        ->middleware('throttle:match-upload')
        ->name('store');

    Route::get('/{upload}', [MatchUploadController::class, 'show'])
        ->where('upload', $uuid)
        ->middleware('throttle:api')
        ->name('show');

    Route::put('/{upload}/parts/{number}', [MatchUploadController::class, 'part'])
        ->where(['upload' => $uuid, 'number' => '[1-9][0-9]{0,3}'])
        ->middleware('throttle:match-upload-parts')
        ->name('parts.store');

    Route::post('/{upload}/complete', [MatchUploadController::class, 'complete'])
        ->where('upload', $uuid)
        ->middleware('throttle:match-upload')
        ->name('complete');

    Route::delete('/{upload}', [MatchUploadController::class, 'destroy'])
        ->where('upload', $uuid)
        ->middleware('throttle:match-upload')
        ->name('destroy');
});
