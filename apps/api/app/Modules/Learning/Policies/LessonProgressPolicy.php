<?php

namespace App\Modules\Learning\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\LessonProgress;

/** Progresso de aula: só o dono (R13), como EnrollmentPolicy. */
class LessonProgressPolicy
{
    public function view(User $user, LessonProgress $progress): bool
    {
        return $progress->user_id === $user->getKey();
    }
}
