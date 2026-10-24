<?php

namespace App\Modules\Learning\Queries;

use App\Modules\Academy\Enums\PublicationStatus;
use App\Modules\Academy\Models\Course;
use App\Modules\Academy\Models\Lesson;
use App\Modules\Academy\Models\Quiz;
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

    /**
     * Progresso do jogador num curso público: 4 consultas (matrícula, aulas públicas, progresso das
     * aulas e resumo dos quizzes ativos).
     */
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
        $quizzes = $this->quizSummaries($user, $lessons->pluck('id')->all());

        return $this->build($enrollment, $lessons, $completions, $quizzes);
    }

    /**
     * Resumo do quiz **ativo** de cada aula (contrato de /progress): tentativas usadas (abertas e
     * enviadas), melhor nota e se já foi aprovado. Sem gabarito, nada que a revisão proteja.
     *
     * @param  list<string>  $lessonIds
     * @return array<string, array{attempts_used: int, best_score_percent: float|null, passed: bool}>
     */
    private function quizSummaries(User $user, array $lessonIds): array
    {
        if ($lessonIds === []) {
            return [];
        }

        $rows = Quiz::query()
            ->whereNull('quizzes.retired_at')
            ->whereIn('quizzes.lesson_id', $lessonIds)
            ->leftJoin('quiz_attempts', function ($join) use ($user) {
                $join->on('quiz_attempts.quiz_id', '=', 'quizzes.id')->where('quiz_attempts.user_id', $user->getKey());
            })
            ->groupBy('quizzes.id', 'quizzes.lesson_id')
            ->selectRaw('quizzes.lesson_id, count(quiz_attempts.id) as used, max(quiz_attempts.score_percent) as best, coalesce(bool_or(quiz_attempts.passed), false) as passed')
            ->toBase()
            ->get();

        $summaries = [];
        foreach ($rows as $row) {
            $summaries[(string) $row->lesson_id] = [
                'attempts_used' => (int) $row->used,
                'best_score_percent' => $row->best === null ? null : (float) $row->best,
                'passed' => (bool) $row->passed,
            ];
        }

        return $summaries;
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
        // Agrupadas uma vez: filtrar a coleção inteira a cada matrícula custava matrículas × aulas
        // (162 ms com 21 matrículas e 803 aulas na medição da etapa 2.7d).
        $lessonsByCourse = $this->publicLessons($courseIds)->groupBy('course_id');
        $completions = $this->completions($user, $courseIds);
        $completionsByCourse = $completions->groupBy('course_id');

        return $enrollments->map(function (Enrollment $enrollment) use ($lessonsByCourse, $completions, $completionsByCourse): EnrollmentSummary {
            if ($enrollment->course->status !== PublicationStatus::Published) {
                return new EnrollmentSummary(
                    $enrollment,
                    completedLessons: $completionsByCourse->get($enrollment->course_id, collect())
                        ->where('status', LessonProgressStatus::Completed)->count(),
                    totalLessons: null,
                    nextLesson: null,
                );
            }

            $progress = $this->build($enrollment, $lessonsByCourse->get($enrollment->course_id, collect()), $completions);

            return new EnrollmentSummary($enrollment, $progress->completedLessons, $progress->totalLessons, $progress->nextLesson);
        })->values()->all();
    }

    /**
     * @param  Collection<int, Lesson>  $lessons  aulas públicas do curso da matrícula, em ordem
     * @param  Collection<string, LessonProgress>  $completions  progresso do jogador por lesson_id (concluídas e em andamento)
     * @param  array<string, array{attempts_used: int, best_score_percent: float|null, passed: bool}>|null  $quizzes  null = sem resumo de quiz
     */
    private function build(Enrollment $enrollment, Collection $lessons, Collection $completions, ?array $quizzes = null): CourseProgress
    {
        $items = [];
        $next = null;
        foreach ($lessons as $lesson) {
            $progress = $completions->get($lesson->id);
            $completion = $progress?->status === LessonProgressStatus::Completed ? $progress : null;
            $item = new LessonProgressItem(
                slug: $lesson->slug,
                title: $lesson->title,
                completed: $completion !== null,
                completedAt: $completion?->completed_at,
                updatedSinceCompletion: $completion !== null && $completion->completed_content_hash !== $lesson->content_hash,
                inProgress: $progress?->status === LessonProgressStatus::InProgress,
                quiz: $quizzes[$lesson->id] ?? null,
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
     * Progresso do jogador nas aulas dos cursos: conclusões (inclusive de aulas arquivadas, para o
     * histórico) e aulas em andamento (quiz iniciado, R10).
     *
     * @param  list<string>  $courseIds
     * @return Collection<string, LessonProgress>
     */
    private function completions(User $user, array $courseIds): Collection
    {
        return LessonProgress::query()
            ->where('user_id', $user->getKey())
            ->whereIn('course_id', $courseIds)
            ->get(['lesson_id', 'course_id', 'status', 'completed_at', 'completed_content_hash'])
            ->keyBy('lesson_id');
    }
}
