<?php

namespace App\Modules\Learning\Enums;

/**
 * Estado do progresso de uma aula (ADR 0017). Sem linha em `lesson_progress`, a aula não foi
 * iniciada. `InProgress` fica reservado para o início de tentativa de quiz (etapa 2.5, R10):
 * ler a aula não grava nada.
 */
enum LessonProgressStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
