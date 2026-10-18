<?php

namespace App\Modules\Learning\Support;

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\CourseModule;
use App\Modules\Academy\Models\Lesson;

/**
 * Confere a visibilidade (R2) **travando** as linhas lidas com `FOR SHARE`, dentro da transação de
 * uma escrita do Learning. Enquanto a matrícula ou a conclusão não termina, a importação não
 * consegue arquivar o curso, o módulo ou a aula (o UPDATE dela espera); se a importação chegou
 * antes, o `FOR SHARE` espera o COMMIT dela e reavalia o status. Assim nada é gravado em conteúdo
 * que deixou de ser público no meio da operação.
 *
 * Ordem das travas igual à da importação (curso → módulo → aula), para não haver deadlock.
 * Só para uso dentro de `DB::transaction`.
 */
final class PublicContentLocks
{
    public function course(string $slug): ?Course
    {
        return Course::query()
            ->where('courses.slug', $slug)
            ->where('courses.status', PublicationStatus::Published->value)
            ->sharedLock()
            ->first();
    }

    public function lesson(Course $course, string $slug): ?Lesson
    {
        $published = PublicationStatus::Published->value;

        // Sem trava: só para saber o módulo e travá-lo antes da aula.
        $moduleId = Lesson::query()
            ->where('lessons.course_id', $course->id)
            ->where('lessons.slug', $slug)
            ->value('module_id');
        if ($moduleId === null) {
            return null;
        }

        $modulePublic = CourseModule::query()
            ->whereKey($moduleId)
            ->where('course_modules.status', $published)
            ->sharedLock()
            ->exists();
        if (! $modulePublic) {
            return null;
        }

        // Reconfere o módulo: a aula pode ter sido movida entre a primeira leitura e a trava.
        $lesson = Lesson::query()
            ->where('lessons.course_id', $course->id)
            ->where('lessons.slug', $slug)
            ->where('lessons.module_id', $moduleId)
            ->where('lessons.status', $published)
            ->sharedLock()
            ->first();
        $lesson?->setRelation('course', $course);

        return $lesson;
    }
}
