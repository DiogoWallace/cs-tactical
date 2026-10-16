<?php

use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Middleware\EnsureFrontendSession;
use App\Modules\Identity\Http\Middleware\RejectAuthenticated;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

/*
| Autenticação por sessão (ADR 0010). Controllers do Fortify, rotas declaradas aqui.
| Todas exigem uma requisição do frontend com sessão (Sanctum stateful + CSRF).
*/
Route::prefix('auth')->name('auth.')->middleware(EnsureFrontendSession::class)->group(function () {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware([RejectAuthenticated::class, 'throttle:register'])
        ->name('register');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware([RejectAuthenticated::class, 'throttle:login'])
        ->name('login');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->name('logout');
});

Route::get('/me', MeController::class)
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('me');
