<?php

namespace App\Modules\Learning;

use App\Modules\Academy\Contracts\QuizUsage;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use App\Modules\Learning\Models\QuizAttempt;
use App\Modules\Learning\Policies\EnrollmentPolicy;
use App\Modules\Learning\Policies\LessonProgressPolicy;
use App\Modules\Learning\Policies\QuizAttemptPolicy;
use App\Modules\Learning\Support\AttemptsQuizUsage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo Learning: matrículas, conclusões, progresso e tentativas de quiz de cada jogador
 * (ADR 0015, ADR 0017, ADR 0018).
 *
 * Depende da Academy (catálogo e visibilidade), nunca o contrário: a Academy só conhece o contrato
 * `QuizUsage`, que o Learning implementa a partir das tentativas (etapa 2.5).
 */
class LearningServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Registrado depois da Academy: substitui a implementação nula dela.
        $this->app->bind(QuizUsage::class, AttemptsQuizUsage::class);
    }

    public function boot(): void
    {
        Gate::policy(Enrollment::class, EnrollmentPolicy::class);
        Gate::policy(LessonProgress::class, LessonProgressPolicy::class);
        Gate::policy(QuizAttempt::class, QuizAttemptPolicy::class);

        // Por usuário: as rotas exigem sessão (docs/architecture/academy.md#limites-de-requisição).
        RateLimiter::for('learning-write', fn (Request $request) => Limit::perMinute(30)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));
        RateLimiter::for('quiz-attempt', fn (Request $request) => Limit::perMinute(10)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('v1.')
            ->group(__DIR__.'/routes.php');
    }
}
