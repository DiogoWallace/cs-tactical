<?php

use App\Modules\System\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Sem sessão: o health check precisa responder mesmo com o Redis (onde ficam as sessões) fora do ar.
Route::get('/health', HealthController::class)
    ->withoutMiddleware(EnsureFrontendRequestsAreStateful::class)
    ->name('health');
