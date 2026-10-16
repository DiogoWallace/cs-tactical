<?php

namespace App\Modules\Identity;

use App\Modules\Identity\Actions\CreateNewUser;
use App\Modules\Identity\Actions\ResetUserPassword;
use App\Modules\Identity\Http\Responses\FailedPasswordResetResponse;
use App\Modules\Identity\Http\Responses\LoginResponse;
use App\Modules\Identity\Http\Responses\PasswordResetLinkResponse;
use App\Modules\Identity\Http\Responses\RegisterResponse;
use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\ProfilePolicy;
use App\Modules\Identity\Policies\UserPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\Sanctum;

/**
 * Módulo Identity: contas, perfis e autenticação por sessão (ADR 0003 e ADR 0010).
 */
class IdentityServiceProvider extends ServiceProvider
{
    /** Caminho da rota de verificação na API; o link do e-mail troca este prefixo pela página do frontend. */
    private const VERIFY_EMAIL_API_PREFIX = '/api/v1/auth/email/verify/';

    public function register(): void
    {
        // Rotas declaradas pelo módulo (routes.php), não pelo pacote.
        Fortify::ignoreRoutes();

        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);

        // "Esqueci minha senha" responde igual com ou sem conta; redefinição recusada tem uma
        // única mensagem. Ver as classes de resposta.
        $this->app->singleton(SuccessfulPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->singleton(FailedPasswordResetLinkRequestResponse::class, PasswordResetLinkResponse::class);
        $this->app->singleton(FailedPasswordResetResponseContract::class, FailedPasswordResetResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Só sessão (ADR 0003): o Sanctum ignora o header Authorization. Sem isto, um
        // "Bearer ..." faria o guard consultar personal_access_tokens, tabela que não existe.
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);

        Password::defaults(fn () => Password::min(8)->max(72));

        Gate::policy(Profile::class, ProfilePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        $this->configureEmailLinks();
        $this->configureRateLimiting();

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('v1.')
            ->group(__DIR__.'/routes.php');
    }

    /**
     * Links dos e-mails apontam para páginas do frontend (ADR 0010).
     *
     * - Verificação: URL assinada **relativa** da rota da API, com o prefixo trocado pela página
     *   /verificar-email do frontend. A página chama a API com o mesmo caminho e a mesma query,
     *   e a assinatura é validada sem depender do host (`signed:relative`).
     * - Redefinição de senha: token e e-mail no fragmento (#...), que o navegador não envia ao
     *   servidor: não aparecem em logs de acesso nem no header Referer.
     */
    private function configureEmailLinks(): void
    {
        VerifyEmail::createUrlUsing(function (User $user): string {
            $relative = URL::temporarySignedRoute(
                'v1.auth.verification.verify',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
                absolute: false,
            );

            return config('app.frontend_url').'/verificar-email/'.Str::after($relative, self::VERIFY_EMAIL_API_PREFIX);
        });

        ResetPassword::createUrlUsing(fn (User $user, string $token): string => config('app.frontend_url')
            .'/redefinir-senha#'.http_build_query(['token' => $token, 'email' => $user->getEmailForPasswordReset()]));
    }

    /**
     * Contadores no Redis, por IP real do cliente (ADR 0009) ou por usuário.
     * Ver docs/architecture/autenticacao.md#rate-limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by($this->emailKey($request).'|'.$request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(5)->by($this->emailKey($request).'|'.$request->ip()));

        RateLimiter::for('register', fn (Request $request) => Limit::perMinute(3)->by($request->ip()));

        // Troca de senha e pedido de troca de e-mail: exigem a senha atual.
        RateLimiter::for('credentials', fn (Request $request) => Limit::perMinute(5)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));

        RateLimiter::for('verification', fn (Request $request) => Limit::perMinute(6)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip(),
        ));
    }

    /** E-mail normalizado para chave de limite. Só texto entra; outros tipos são barrados por EnsureStringFields. */
    private function emailKey(Request $request): string
    {
        $email = $request->input(Fortify::username());

        return is_string($email) ? Str::transliterate(Str::lower($email)) : '';
    }
}
