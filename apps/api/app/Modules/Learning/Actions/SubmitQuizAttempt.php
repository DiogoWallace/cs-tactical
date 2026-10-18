<?php

namespace App\Modules\Learning\Actions;

use App\Modules\Academy\Models\Quiz;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Enums\QuizAttemptStatus;
use App\Modules\Learning\Exceptions\QuizAttemptConflict;
use App\Modules\Learning\Models\QuizAttempt;
use App\Modules\Learning\Models\QuizAttemptAnswer;
use App\Modules\Learning\Support\PublicContentLocks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Envio de uma tentativa (R17, R18; ADR 0018), numa transação:
 *
 * 1. só tentativa do próprio jogador (a busca já filtra pela sessão: alheia ou inexistente → 404);
 * 2. aula ainda pública, travada como nas demais escritas (arquivada → 404, ADR 0018 item 15);
 * 3. a tentativa travada com `FOR UPDATE`: dois envios simultâneos são serializados e o segundo
 *    encontra a tentativa enviada → 409 `quiz_attempt_already_submitted`;
 * 4. respostas validadas **contra o snapshot** (422): todas as perguntas, sem repetição, opções do
 *    snapshot daquela pergunta, exatamente uma em `single_choice`;
 * 5. correção no servidor: cada pergunta vale 1 ponto e só pontua com a seleção exata (P6); nota
 *    com duas casas; aprovação = nota ≥ nota mínima do snapshot.
 *
 * Nada do cliente além das opções escolhidas é usado: nota e aprovação nunca vêm do navegador.
 */
class SubmitQuizAttempt
{
    public function __construct(private readonly PublicContentLocks $locks) {}

    /**
     * @param  array<array-key, mixed>  $answers  `[{question_id, option_ids}]`, já com tipos básicos conferidos
     */
    public function handle(User $user, string $attemptId, array $answers): ?QuizAttempt
    {
        return DB::transaction(function () use ($user, $attemptId, $answers): ?QuizAttempt {
            $userId = (string) $user->getKey();
            $quizId = QuizAttempt::query()->whereKey($attemptId)->where('user_id', $userId)->value('quiz_id');
            $lessonId = $quizId === null ? null : Quiz::query()->whereKey($quizId)->value('lesson_id');
            $lesson = $lessonId === null ? null : $this->locks->lessonById($lessonId);
            if ($lesson === null) {
                return null;
            }

            $attempt = QuizAttempt::query()->whereKey($attemptId)->where('user_id', $userId)->lockForUpdate()->first();
            if ($attempt === null) {
                return null;
            }
            if ($attempt->status === QuizAttemptStatus::Submitted) {
                throw QuizAttemptConflict::alreadySubmitted();
            }

            $selected = $this->validated($attempt, $answers);
            $now = Carbon::now();

            $correct = 0;
            $rows = [];
            foreach ($attempt->snapshot['questions'] as $question) {
                $right = array_values(array_map(fn (array $option) => $option['id'], array_filter($question['options'], fn (array $option) => $option['correct'])));
                $chosen = $selected[$question['id']];
                sort($right);
                sort($chosen);
                $isCorrect = $right === $chosen;
                $correct += $isCorrect ? 1 : 0;
                $rows[] = ['question_id' => $question['id'], 'selected_option_ids' => $selected[$question['id']], 'is_correct' => $isCorrect];
            }

            $total = count($attempt->snapshot['questions']);
            $score = round($correct / $total * 100, 2);
            $attempt->forceFill([
                'status' => QuizAttemptStatus::Submitted,
                'submitted_at' => $now,
                'question_count' => $total,
                'correct_count' => $correct,
                'score_percent' => $score,
                'passed' => $score >= $attempt->snapshot['pass_percent'],
            ])->save();

            foreach ($rows as $row) {
                $answer = new QuizAttemptAnswer;
                $answer->forceFill(['id' => (string) Str::uuid7(), 'attempt_id' => $attempt->id, ...$row])->save();
            }

            DB::table('enrollments')->where('user_id', $userId)->where('course_id', $lesson->course_id)
                ->where('last_activity_at', '<', $now)
                ->update(['last_activity_at' => $now, 'updated_at' => $now]);

            return $attempt;
        });
    }

    /**
     * Opções escolhidas por pergunta, conferidas contra o snapshot da tentativa.
     *
     * @param  array<array-key, mixed>  $answers
     * @return array<string, list<string>>
     */
    private function validated(QuizAttempt $attempt, array $answers): array
    {
        $questions = [];
        foreach ($attempt->snapshot['questions'] as $question) {
            $questions[$question['id']] = $question;
        }

        $errors = [];
        $selected = [];
        foreach (array_values($answers) as $index => $answer) {
            $questionId = $answer['question_id'];
            $optionIds = array_values($answer['option_ids']);
            $question = $questions[$questionId] ?? null;
            if ($question === null) {
                $errors["answers.{$index}.question_id"][] = 'Pergunta que não faz parte desta tentativa.';

                continue;
            }
            if (isset($selected[$questionId])) {
                $errors["answers.{$index}.question_id"][] = 'Pergunta respondida mais de uma vez.';

                continue;
            }
            $allowed = array_map(fn (array $option) => $option['id'], $question['options']);
            if (array_diff($optionIds, $allowed) !== [] || count(array_unique($optionIds)) !== count($optionIds)) {
                $errors["answers.{$index}.option_ids"][] = 'Opção que não pertence a esta pergunta.';

                continue;
            }
            if ($question['kind'] === 'single_choice' && count($optionIds) !== 1) {
                $errors["answers.{$index}.option_ids"][] = 'Escolha exatamente uma opção.';

                continue;
            }
            $selected[$questionId] = $optionIds;
        }

        if ($errors === [] && count($selected) !== count($questions)) {
            $errors['answers'][] = 'Responda todas as perguntas.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $selected;
    }
}
