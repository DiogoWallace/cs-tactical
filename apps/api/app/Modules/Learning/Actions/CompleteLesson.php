<?php

namespace App\Modules\Learning\Actions;

use App\Modules\Academy\Models\Course;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Enums\LessonProgressStatus;
use App\Modules\Learning\Queries\CourseProgress;
use App\Modules\Learning\Queries\ProgressQuery;
use App\Modules\Learning\Support\PublicContentLocks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Conclusão de uma aula pública (R8, R9, R12; ADR 0017), numa transação:
 *
 * 1. trava curso, módulo e aula conferindo que são públicos (PublicContentLocks);
 * 2. cria a matrícula se faltar (auto-matrícula);
 * 3. grava a conclusão com o hash do conteúdo **lido sob a trava**. INSERT ... ON CONFLICT: duas
 *    requisições simultâneas geram uma linha só, e uma conclusão existente não é alterada (a
 *    primeira data vale);
 * 4. só se algo mudou: atualiza `last_activity_at` e, se todas as aulas públicas estão concluídas,
 *    grava `enrollments.completed_at` (uma vez; nunca apagado).
 *
 * Repetir a conclusão não escreve nada. Devolve o progresso do curso, ou null se a aula não é
 * pública (o controller responde 404).
 */
class CompleteLesson
{
    public function __construct(
        private readonly PublicContentLocks $locks,
        private readonly ProgressQuery $progress,
    ) {}

    public function handle(User $user, string $courseSlug, string $lessonSlug): ?CourseProgress
    {
        return DB::transaction(function () use ($user, $courseSlug, $lessonSlug): ?CourseProgress {
            $course = $this->locks->course($courseSlug);
            $lesson = $course === null ? null : $this->locks->lesson($course, $lessonSlug);
            if ($course === null || $lesson === null) {
                return null;
            }

            $now = Carbon::now();
            $userId = (string) $user->getKey();
            $enrolled = EnrollInCourse::ensure($userId, $course->id, $now);

            // Já concluída: nada a gravar (a primeira data e o hash daquele momento valem, R9).
            $alreadyCompleted = DB::table('lesson_progress')
                ->where('user_id', $userId)
                ->where('lesson_id', $lesson->id)
                ->where('status', LessonProgressStatus::Completed->value)
                ->exists();

            $completed = ! $alreadyCompleted && DB::affectingStatement(
                'INSERT INTO lesson_progress (id, user_id, lesson_id, course_id, status, started_at, completed_at, completed_content_hash, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, lesson_id) DO UPDATE
                    SET status = EXCLUDED.status, completed_at = EXCLUDED.completed_at,
                        completed_content_hash = EXCLUDED.completed_content_hash, updated_at = EXCLUDED.updated_at
                  WHERE lesson_progress.status <> EXCLUDED.status',
                [(string) Str::uuid7(), $userId, $lesson->id, $course->id, LessonProgressStatus::Completed->value,
                    $now, $now, $lesson->content_hash, $now, $now],
            ) === 1;

            // Matrícula recém-criada já nasce com `last_activity_at = now`.
            if ($completed && ! $enrolled) {
                DB::table('enrollments')
                    ->where('user_id', $userId)
                    ->where('course_id', $course->id)
                    ->where('last_activity_at', '<', $now)
                    ->update(['last_activity_at' => $now, 'updated_at' => $now]);
            }
            if ($completed && $this->allPublicLessonsCompleted($userId, $course)) {
                DB::table('enrollments')
                    ->where('user_id', $userId)
                    ->where('course_id', $course->id)
                    ->whereNull('completed_at')
                    ->update(['completed_at' => $now, 'updated_at' => $now]);
            }

            return $this->progress->forCourse($user, $course);
        });
    }

    /** Nenhuma aula pública do curso sem conclusão do jogador (R12). */
    private function allPublicLessonsCompleted(string $userId, Course $course): bool
    {
        return ! $course->lessons()
            ->visible()
            ->whereNotExists(fn ($query) => $query->selectRaw('1')
                ->from('lesson_progress')
                ->whereColumn('lesson_progress.lesson_id', 'lessons.id')
                ->where('lesson_progress.user_id', $userId)
                ->where('lesson_progress.status', LessonProgressStatus::Completed->value))
            ->exists();
    }
}
