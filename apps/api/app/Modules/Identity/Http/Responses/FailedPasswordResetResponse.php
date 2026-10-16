<?php

namespace App\Modules\Identity\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetResponse as FailedPasswordResetResponseContract;

/**
 * Redefinição recusada (token inválido, expirado, já usado ou de outro e-mail): sempre a
 * mesma mensagem, no campo `token`. O padrão do Fortify diferenciava "usuário não encontrado"
 * de "token inválido", o que revelaria quais e-mails têm conta.
 */
class FailedPasswordResetResponse implements FailedPasswordResetResponseContract
{
    /** @param  Request  $request */
    public function toResponse($request): never
    {
        throw ValidationException::withMessages([
            'token' => [__('passwords.token')],
        ]);
    }
}
