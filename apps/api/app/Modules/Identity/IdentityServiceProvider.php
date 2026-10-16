<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Actions\CreateNewUser;
use App\Modules\Identity\Http\Responses\LoginResponse;
use App\Modules\Identity\Http\Responses\RegisterResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;

/**
 * Módulo Identity: contas, perfis e autenticação por sessão (ADR 0003 e ADR 0010).
 */
class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Rotas declaradas pelo módulo (routes.php), não pelo pacote.
        Fortify::ignoreRoutes();

        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);

        // Só sessão (ADR 0003): o Sanctum ignora o header Authorization. Sem isto, um
        // "Bearer ..." faria o guard consultar personal_access_tokens, tabela que não existe.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);

        Password::defaults(fn () => Password::min(8)->max(72));

        $this->configureRateLimiting();

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('v1.')
            ->group(__DIR__.'/routes.php');
    }

    /**
     * Contadores no Redis, por IP real do cliente (ADR 0009) ou por usuário.
     * Ver docs/architecture/autenticacao.md#rate-limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            Str::transliterate(Str::lower((string) $request->input(Fortify::username()))).'|'.$request->ip(),
        ));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));
    }
}
