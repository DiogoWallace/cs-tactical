<?php

namespace App\Modules\Learning\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\QuizAttempt;

/**
 * Tentativa de quiz: só o dono (R13, R17). As rotas buscam a tentativa já filtrando pelo jogador da
 * sessão (tentativa alheia responde 404, sem revelar que existe); a Policy é a segunda barreira.
 */
class QuizAttemptPolicy
{
    public function view(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->user_id === $user->getKey();
    }

    public function submit(User $user, QuizAttempt $attempt): bool
    {
        return $attempt->user_id === $user->getKey();
    }
}
