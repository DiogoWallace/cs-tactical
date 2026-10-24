<?php

namespace App\Support\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nenhuma resposta da API fica guardada, nem no navegador (etapa 2.7b, achado A1).
 *
 * O padrão do Laravel é `no-cache, private`: caches compartilhados não guardam, mas o navegador
 * pode gravar o JSON (dados da conta, progresso, tentativas) e reaproveitá-lo depois de validar.
 * Como a API não usa cache HTTP em nenhuma rota (a mídia da Academy já era `no-store`), todas as
 * respostas sob `api/*`, inclusive erros, saem com `no-store, private`.
 *
 * É middleware global, não do grupo `api`: no grupo, a ordenação por prioridade do Laravel põe
 * `Authenticate` antes dele, e a 401 voltaria sem passar por aqui; rota inexistente (404) nem
 * chega a entrar no grupo.
 */
class NoStoreApiResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
