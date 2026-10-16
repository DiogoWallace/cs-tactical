<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Equivalente ao middleware `guest` para a API: em vez de redirecionar (o que não serve
 * a um cliente JSON), responde 409. Impede cadastrar ou entrar com outra conta sem sair.
 */
class RejectAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->guard(config('fortify.guard'))->check()) {
            return response()->json([
                'message' => 'Already authenticated.',
                'code' => 'already_authenticated',
            ], Response::HTTP_CONFLICT);
        }

        return $next($request);
    }
}
