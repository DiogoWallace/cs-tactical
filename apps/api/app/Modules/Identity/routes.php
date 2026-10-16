<?php

use App\Modules\Identity\Http\Controllers\ChangePasswordController;
use App\Modules\Identity\Http\Controllers\EmailChangeController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\UpdateProfileController;
use App\Modules\Identity\Http\Middleware\EnsureFrontendSession;
use App\Modules\Identity\Http\Middleware\EnsureStringFields;
use App\Modules\Identity\Http\Middleware\RejectAuthenticated;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\EmailVerificationNotificationController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;
use Laravel\Fortify\Http\Controllers\VerifyEmailController;

/*
| Autenticação por sessão (ADR 0010). Controllers do Fortify, rotas declaradas aqui.
| Todas exigem uma requisição do frontend com sessão (Sanctum stateful + CSRF).
*/
Route::prefix('auth')->name('auth.')->middleware(EnsureFrontendSession::class)->group(function () {
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware([RejectAuthenticated::class, EnsureStringFields::class, 'throttle:register'])
        ->name('register');

    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware([RejectAuthenticated::class, EnsureStringFields::class, 'throttle:login'])
        ->name('login');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->middleware(['auth:sanctum', 'throttle:api'])
        ->name('logout');

    // Recuperação de senha (etapa 1.4).
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware([RejectAuthenticated::class, EnsureStringFields::class, 'throttle:password-reset'])
        ->name('password.email');

    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware([RejectAuthenticated::class, EnsureStringFields::class, 'throttle:password-reset'])
        ->name('password.update');

    // Verificação de e-mail (etapa 1.4). O link assinado é aberto pelo frontend, que chama esta
    // rota com o mesmo caminho e query; a assinatura é relativa (não depende do host).
    Route::get('/email/verify/{id}/{hash}', VerifyEmailController::class)
        ->middleware(['auth:sanctum', ValidateSignature::relative(), 'throttle:verification'])
        ->name('verification.verify');

    Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
        ->middleware(['auth:sanctum', 'throttle:verification'])
        ->name('verification.send');
});

Route::get('/me', MeController::class)
    ->middleware(['auth:sanctum', 'throttle:api'])
    ->name('me');

/*
| Conta do usuário autenticado (etapa 1.5). Só o próprio usuário: as rotas não recebem o id
| de outra conta, e as Policies (ProfilePolicy, UserPolicy) confirmam a posse.
*/
Route::prefix('me')->name('me.')->middleware('auth:sanctum')->group(function () {
    Route::patch('/profile', UpdateProfileController::class)
        ->middleware('throttle:api')
        ->name('profile.update');

    // Exigem a senha atual: limite próprio contra tentativa e erro.
    Route::put('/password', ChangePasswordController::class)
        ->middleware('throttle:credentials')
        ->name('password.update');

    Route::post('/email', [EmailChangeController::class, 'store'])
        ->middleware('throttle:credentials')
        ->name('email.request');

    Route::delete('/email', [EmailChangeController::class, 'destroy'])
        ->middleware('throttle:api')
        ->name('email.cancel');

    Route::get('/email/confirm/{id}/{hash}', [EmailChangeController::class, 'confirm'])
        ->middleware([ValidateSignature::relative(), 'throttle:verification'])
        ->name('email.confirm');
});
