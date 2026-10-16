<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login, cadastro e logout só fazem sentido com a sessão do frontend (ADR 0003).
 *
 * O Sanctum só inicia sessão (e só verifica CSRF) para origens em SANCTUM_STATEFUL_DOMAINS.
 * Sem esta verificação, uma chamada de outra origem ou sem Origin autenticaria numa sessão
 * que nunca chega ao cliente, gravando sessões órfãs no Redis.
 */
class EnsureFrontendSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return response()->json([
                'message' => 'This endpoint is only available to the web application.',
                'code' => 'frontend_session_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
