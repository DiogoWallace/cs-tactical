<?php

namespace App\Modules\Identity\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * "Esqueci minha senha": a mesma resposta 200 em todos os casos.
 *
 * Usada tanto para o sucesso quanto para as falhas do broker (e-mail não cadastrado, pedido
 * repetido antes de 60 s). Responder diferente revelaria quais e-mails têm conta.
 */
class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    /** @param  Request  $request */
    public function toResponse($request): JsonResponse
    {
        return response()->json([
            'message' => __('passwords.sent'),
        ]);
    }
}
