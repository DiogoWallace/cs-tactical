<?php

namespace App\Modules\Learning\Enums;

/** Estado de uma tentativa de quiz (ADR 0018). Uma aberta por vez por jogador e quiz. */
enum QuizAttemptStatus: string
{
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
}
