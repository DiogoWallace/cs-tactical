<?php

namespace App\Modules\Learning;

use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Policies\EnrollmentPolicy;
use App\Modules\Learning\Policies\LessonProgressPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo Learning: matrículas, conclusões e progresso de cada jogador (ADR 0015, ADR 0017).
 *
 * Depende da Academy (catálogo e visibilidade), nunca o contrário. Etapa 2.4; tentativas de quiz
 * chegam na 2.5 (e com elas a implementação real de `QuizUsage`).
 */
class LearningServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Enrollment::class, EnrollmentPolicy::class);
        Gate::policy(LessonProgress::class, LessonProgressPolicy::class);

        // Por usuário: as rotas exigem sessão (docs/architecture/academy.md#limites-de-requisição).
        RateLimiter::for('learning-write', fn (Request $request) => Limit::perMinute(30)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('v1.')
            ->group(__DIR__.'/routes.php');
    }
}
