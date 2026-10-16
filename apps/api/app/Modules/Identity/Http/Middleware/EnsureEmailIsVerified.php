<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Funcionalidades que exigem e-mail verificado (decisão de produto da Fase 1: quem não
 * verificou pode entrar, mas não usar o que for protegido). Use depois de `auth:sanctum`.
 *
 * Responde 403 com `code: email_not_verified`, para o frontend mostrar o aviso de verificação.
 * O middleware `verified` do Laravel responderia 403 genérico ou redirecionaria.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof MustVerifyEmail || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Your email address is not verified.',
                'code' => 'email_not_verified',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
