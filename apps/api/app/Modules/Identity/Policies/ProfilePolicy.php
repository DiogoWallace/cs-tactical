<?php

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;

/**
 * Perfil: só o dono edita. Papéis de plataforma (etapa 1.6) não dão acesso ao perfil alheio.
 */
class ProfilePolicy
{
    public function update(User $user, Profile $profile): bool
    {
        return $profile->user_id === $user->getKey();
    }
}
