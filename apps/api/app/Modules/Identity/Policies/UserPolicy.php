<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;

/**
 * Credenciais da conta (e-mail e senha) e vínculo da conta Steam: só o próprio usuário altera.
 * Nenhum papel de plataforma (etapa 1.6) altera credenciais ou o vínculo de outra conta.
 */
class UserPolicy
{
    public function updateCredentials(User $user, User $account): bool
    {
        return $user->is($account);
    }

    /** Vincular e desvincular a conta Steam (etapa M2). */
    public function manageSteamLink(User $user, User $account): bool
    {
        return $user->is($account);
    }
}
