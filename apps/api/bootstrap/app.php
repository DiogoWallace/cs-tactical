<?php

use App\Support\Http\ApiErrorResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum em modo SPA: requisições vindas do frontend (SANCTUM_STATEFUL_DOMAINS)
        // recebem sessão, cookies criptografados e verificação CSRF. Ver ADR 0003.
        $middleware->statefulApi();

        // IP real do cliente (ADR 0009): os endereços confiáveis vêm de config/trustedproxy.php.
        // Só X-Forwarded-For e X-Forwarded-Proto são lidos; Host, Port e Prefix nunca.
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        // A API não tem página de login: quem não está autenticado recebe 401 em JSON e o
        // frontend decide para onde ir (docs/architecture/autenticacao.md). Sem isto, o Laravel
        // tentaria redirecionar para a rota nomeada "login".
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Corpo padronizado { message, code, errors? } (docs/api/convencoes.md#erros).
        $exceptions->respond(new ApiErrorResponse);
    })->create();
