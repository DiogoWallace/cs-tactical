<?php

namespace App\Modules\Academy;

use App\Modules\Academy\Console\ImportContentCommand;
use App\Modules\Academy\Console\PruneMediaCommand;
use App\Modules\Academy\Contracts\QuizUsage;
use App\Modules\Academy\Support\NoQuizUsage;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo Academy: catálogo de cursos, aulas e quizzes (ADRs 0015 a 0019).
 *
 * Etapa 2.1: banco, importação de conteúdo e limpeza de mídia. Etapa 2.2: API pública de leitura
 * do catálogo e das imagens.
 */
class AcademyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QuizUsage::class, NoQuizUsage::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([ImportContentCommand::class, PruneMediaCommand::class]);
        }

        $this->configureRateLimiting();

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('v1.')
            ->group(__DIR__.'/routes.php');
    }

    /**
     * Por IP real do cliente (ADR 0009): as rotas são públicas. Ver
     * docs/architecture/academy.md#limites-de-requisição.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('academy-public', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));

        // Uma página do catálogo ou de aula carrega várias imagens de uma vez.
        RateLimiter::for('academy-media', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));
    }
}
