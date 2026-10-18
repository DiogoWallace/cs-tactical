<?php

namespace App\Modules\Learning\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\Enrollment;

/**
 * Matrícula: só o dono (R13). Nenhum papel de plataforma, nem `admin`, lê matrícula alheia na
 * Fase 2. As rotas nunca recebem id de matrícula nem de usuário (tudo vem da sessão); a Policy é a
 * segunda barreira (docs/architecture/academy.md#matriz-de-autorização).
 */
class EnrollmentPolicy
{
    public function view(User $user, Enrollment $enrollment): bool
    {
        return $enrollment->user_id === $user->getKey();
    }
}
