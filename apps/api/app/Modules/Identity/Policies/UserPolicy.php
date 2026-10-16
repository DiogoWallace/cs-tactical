<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;

/**
 * Credenciais da conta (e-mail e senha): só o próprio usuário altera.
 * Nenhum papel de plataforma (etapa 1.6) altera credenciais de outra conta.
 */
class UserPolicy
{
    public function updateCredentials(User $user, User $account): bool
    {
        return $user->is($account);
    }
}
