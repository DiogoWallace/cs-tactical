<?php

namespace App\Modules\Learning\Queries;

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Enums\LessonProgressStatus;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Models\LessonProgress;
use Illuminate\Support\Collection;

/**
 * Leituras do Learning (docs/api/academy-v1.md). Sempre do usuário da sessão (R13) e sempre
 * juntando com a visibilidade do catálogo (ADR 0015, ADR 0017): conteúdo que deixou de ser
 * público não entra no percentual nem em "continuar".
 *
 * Número fixo de consultas, qualquer que seja o número de aulas ou matrículas (sem N+1).
 */
final class ProgressQuery
{
    /** Curso público pelo slug (mesma regra do catálogo, R2). */
    public function publicCourse(string $slug): ?Course
    {
        return Course::query()->visible()->where('courses.slug', $slug)->first();
    }

    /** Progresso do jogador num curso público: 3 consultas (matrícula, aulas públicas, conclusões). */
    public function forCourse(User $user, Course $course): CourseProgress
    {
        $enrollment = Enrollment::query()
            ->where('user_id', $user->getKey())
            ->where('course_id', $course->id)
            ->first();
        if ($enrollment === null) {
            return new CourseProgress(null, 0, 0, [], null);
        }
        $enrollment->setRelation('course', $course);

        $lessons = $this->publicLessons([$course->id]);
        $completions = $this->completions($user, [$course->id]);

        return $this->build($enrollment, $lessons, $completions);
    }

    /**
     * "Meu progresso": todas as matrículas, atividade mais recente primeiro. Cursos que deixaram de
     * ser públicos aparecem com o histórico (conclusões gravadas), sem percentual nem "continuar"
     * (R13a). 4 consultas: matrículas, cursos, aulas públicas e conclusões.
     *
     * @return list<EnrollmentSummary>
     */
    public function enrollments(User $user): array
    {
        $enrollments = Enrollment::query()
            ->where('user_id', $user->getKey())
            ->with('course')
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->get();
        if ($enrollments->isEmpty()) {
            return [];
        }

        $courseIds = $enrollments->pluck('course_id')->all();
        $lessons = $this->publicLessons($courseIds);
        $completions = $this->completions($user, $courseIds);

        return $enrollments->map(function (Enrollment $enrollment) use ($lessons, $completions): EnrollmentSummary {
            if ($enrollment->course->status !== PublicationStatus::Published) {
                return new EnrollmentSummary(
                    $enrollment,
                    completedLessons: $completions->where('course_id', $enrollment->course_id)->count(),
                    totalLessons: null,
                    nextLesson: null,
                );
            }

            $progress = $this->build($enrollment, $lessons, $completions);

            return new EnrollmentSummary($enrollment, $progress->completedLessons, $progress->totalLessons, $progress->nextLesson);
        })->values()->all();
    }

    /**
     * @param  Collection<int, Lesson>  $lessons  aulas públicas (de um ou mais cursos), em ordem
     * @param  Collection<string, LessonProgress>  $completions  conclusões do jogador, por lesson_id
     */
    private function build(Enrollment $enrollment, Collection $lessons, Collection $completions): CourseProgress
    {
        $items = [];
        $next = null;
        foreach ($lessons->where('course_id', $enrollment->course_id) as $lesson) {
            $completion = $completions->get($lesson->id);
            $item = new LessonProgressItem(
                slug: $lesson->slug,
                title: $lesson->title,
                completed: $completion !== null,
                completedAt: $completion?->completed_at,
                updatedSinceCompletion: $completion !== null && $completion->completed_content_hash !== $lesson->content_hash,
            );
            $items[] = $item;
            $next ??= $item->completed ? null : $item;
        }

        $completed = count(array_filter($items, fn (LessonProgressItem $item) => $item->completed));

        return new CourseProgress($enrollment, $completed, count($items), $items, $next);
    }

    /**
     * Aulas públicas dos cursos, na ordem do curso (posição do módulo, depois da aula).
     *
     * @param  list<string>  $courseIds
     * @return Collection<int, Lesson>
     */
    private function publicLessons(array $courseIds): Collection
    {
        return Lesson::query()
            ->visible()
            ->whereIn('lessons.course_id', $courseIds)
            ->join('course_modules', 'course_modules.id', '=', 'lessons.module_id')
            ->orderBy('course_modules.position')
            ->orderBy('lessons.position')
            ->get(['lessons.id', 'lessons.course_id', 'lessons.slug', 'lessons.title', 'lessons.content_hash']);
    }

    /**
     * Conclusões do jogador nos cursos (inclusive de aulas arquivadas, para o histórico).
     *
     * @param  list<string>  $courseIds
     * @return Collection<string, LessonProgress>
     */
    private function completions(User $user, array $courseIds): Collection
    {
        return LessonProgress::query()
            ->where('user_id', $user->getKey())
            ->whereIn('course_id', $courseIds)
            ->where('status', LessonProgressStatus::Completed->value)
            ->get(['lesson_id', 'course_id', 'completed_at', 'completed_content_hash'])
            ->keyBy('lesson_id');
    }
}
