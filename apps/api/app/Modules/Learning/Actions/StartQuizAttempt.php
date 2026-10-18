<?php

namespace App\Modules\Learning\Actions;

use App\Modules\Academy\Models\Quiz;
use App\Modules\Academy\Models\QuizOption;
use App\Modules\Academy\Models\QuizQuestion;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Enums\LessonProgressStatus;
use App\Modules\Learning\Enums\QuizAttemptStatus;
use App\Modules\Learning\Exceptions\QuizAttemptConflict;
use App\Modules\Learning\Models\QuizAttempt;
use App\Modules\Learning\Support\PublicContentLocks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Início de tentativa (R14–R16a, ADR 0018), numa transação:
 *
 * 1. quiz **ativo** de aula pública: curso, módulo e aula travados com `FOR SHARE` (como nas
 *    escritas do Learning) e o quiz também, para o snapshot não pegar uma importação pela metade;
 * 2. auto-matrícula (R14) e trava da matrícula (`FOR UPDATE`): serializa as tentativas do jogador
 *    no curso, então contagem, número da tentativa e criação não disputam entre si;
 * 3. regras, nesta ordem: tentativa aberta → devolve a aberta (R15, sem consumir tentativa);
 *    já aprovado em qualquer revisão → 409 `quiz_already_passed` (R16a); limite atingido → 409
 *    `quiz_attempts_exhausted` (R16);
 * 4. snapshot das perguntas e opções ativas, na ordem, com gabarito, explicações e nota mínima;
 *    a aula vira `in_progress` se ainda não concluída (R10) e a atividade da matrícula é atualizada.
 *
 * Devolve [tentativa, criada agora], ou null se o quiz não existe, não está ativo ou a aula não é
 * pública (404).
 */
class StartQuizAttempt
{
    public function __construct(private readonly PublicContentLocks $locks) {}

    /** @return array{0: QuizAttempt, 1: bool}|null */
    public function handle(User $user, string $quizKey): ?array
    {
        return DB::transaction(function () use ($user, $quizKey): ?array {
            $lessonId = Quiz::query()->where('key', $quizKey)->whereNull('retired_at')->value('lesson_id');
            $lesson = $lessonId === null ? null : $this->locks->lessonById($lessonId);
            $quiz = $lesson === null ? null : Quiz::query()
                ->where('key', $quizKey)
                ->where('lesson_id', $lesson->id)
                ->whereNull('retired_at')
                ->sharedLock()
                ->first();
            if ($lesson === null || $quiz === null) {
                return null;
            }

            $userId = (string) $user->getKey();
            $now = Carbon::now();
            $enrolled = EnrollInCourse::ensure($userId, $lesson->course_id, $now);
            DB::table('enrollments')->where('user_id', $userId)->where('course_id', $lesson->course_id)->lockForUpdate()->first();

            $attempts = QuizAttempt::query()->where('user_id', $userId)->where('quiz_id', $quiz->id)->get();
            $open = $attempts->firstWhere('status', QuizAttemptStatus::InProgress);
            if ($open !== null) {
                return [$open, false];
            }
            if ($attempts->contains('passed', true)) {
                throw QuizAttemptConflict::alreadyPassed();
            }
            if ($attempts->count() >= $quiz->max_attempts) {
                throw QuizAttemptConflict::exhausted();
            }

            $attempt = new QuizAttempt;
            $attempt->forceFill([
                'id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'quiz_id' => $quiz->id,
                'attempt_number' => $attempts->count() + 1,
                'quiz_revision' => $quiz->revision,
                'status' => QuizAttemptStatus::InProgress,
                'snapshot' => $this->snapshot($quiz),
                'started_at' => $now,
            ])->save();

            // R10: iniciar o quiz marca a aula como em andamento, sem tocar numa conclusão existente.
            DB::table('lesson_progress')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'user_id' => $userId,
                'lesson_id' => $lesson->id,
                'course_id' => $lesson->course_id,
                'status' => LessonProgressStatus::InProgress->value,
                'started_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (! $enrolled) {
                DB::table('enrollments')->where('user_id', $userId)->where('course_id', $lesson->course_id)
                    ->where('last_activity_at', '<', $now)
                    ->update(['last_activity_at' => $now, 'updated_at' => $now]);
            }

            return [$attempt, true];
        });
    }

    /** @return array{pass_percent: int, questions: list<array<string, mixed>>} */
    private function snapshot(Quiz $quiz): array
    {
        $questions = QuizQuestion::query()->where('quiz_id', $quiz->id)->whereNull('retired_at')->orderBy('position')->get();
        $options = QuizOption::query()
            ->whereIn('question_id', $questions->modelKeys())
            ->whereNull('retired_at')
            ->orderBy('position')
            ->get()
            ->groupBy('question_id');

        return [
            'pass_percent' => $quiz->pass_percent,
            'questions' => $questions->map(fn (QuizQuestion $question) => [
                'id' => $question->id,
                'kind' => $question->kind->value,
                'prompt' => $question->prompt,
                'explanation' => $question->explanation,
                'options' => ($options->get($question->id) ?? collect())->map(fn (QuizOption $option) => [
                    'id' => $option->id,
                    'text' => $option->text,
                    'correct' => $option->is_correct,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
