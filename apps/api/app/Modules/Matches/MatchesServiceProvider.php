<?php

namespace App\Modules\Matches;

use App\Modules\Matches\Console\PruneMatchUploadsCommand;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Policies\MatchUploadPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Módulo Matches: análise de partidas a partir de demos enviadas pelo jogador (ADR 0022).
 *
 * Etapa M3: envio da demo em partes (docs/training/mvp-m3-envio-de-demos.md). A análise (M4) e as
 * telas (M5) vêm depois.
 */
class MatchesServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(MatchUpload::class, MatchUploadPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([PruneMatchUploadsCommand::class]);
        }

        $this->configureRateLimiting();

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('v1.')
            ->group(__DIR__.'/routes.php');
    }

    /** Por usuário (todas as rotas exigem sessão). Decisão U3. */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('match-upload', fn (Request $request) => Limit::perMinute(30)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));

        // Um arquivo de 600 MiB tem 75 partes de 8 MiB.
        RateLimiter::for('match-upload-parts', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));
    }
}
