<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Troca a senha e invalida o que dependia da antiga:
 * - `remember_token` novo: cookies "lembrar-me" de outros dispositivos deixam de valer;
 * - tokens de redefinição pendentes são apagados.
 * As outras sessões caem na próxima requisição (AuthenticateSession do Sanctum compara o hash
 * da senha guardado na sessão). A sessão atual é renovada pelo controller.
 */
class ChangePassword
{
    public function handle(User $user, #[\SensitiveParameter] string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        Password::broker()->deleteToken($user);
    }
}
