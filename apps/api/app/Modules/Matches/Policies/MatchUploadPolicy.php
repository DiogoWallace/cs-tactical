<?php

namespace App\Modules\Matches\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Matches\Models\MatchUpload;

/**
 * Envios de demo: só o dono vê, continua, conclui ou cancela. Nenhum papel de plataforma abre o
 * envio de outro usuário (como no Learning). Segunda barreira: as rotas já buscam pelo dono.
 */
class MatchUploadPolicy
{
    public function view(User $user, MatchUpload $upload): bool
    {
        return $upload->user_id === $user->getKey();
    }

    public function update(User $user, MatchUpload $upload): bool
    {
        return $upload->user_id === $user->getKey();
    }
}
