<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/**
 * Nova senha a partir de um link de redefinição (Fortify `ResetsUserPasswords`).
 *
 * O token já foi validado pelo broker antes desta chamada. Depois dela o Fortify troca o
 * remember_token (cookies "lembrar-me" deixam de valer) e o broker apaga o token (uso único).
 * As demais sessões caem na próxima requisição: o middleware AuthenticateSession do Sanctum
 * compara o hash da senha guardado na sessão.
 */
class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * @param  User  $user
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function reset($user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ])->validate();

        $user->forceFill(['password' => $input['password']])->save();
    }
}
