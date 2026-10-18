<?php

namespace App\Modules\Learning\Quizzes;

use App\Modules\Learning\Enums\QuizAttemptStatus;
use App\Modules\Learning\Models\QuizAttempt;
use App\Modules\Learning\Models\QuizAttemptAnswer;
use Illuminate\Support\Collection;

/**
 * **Único** ponto que descreve tentativas para a API (ADR 0018, item 14; R20–R20b). Toda resposta
 * sobre tentativa (início, envio, detalhe, histórico) passa por aqui:
 *
 * - aberta: perguntas e opções do snapshot, **sem** `correct` nem `explanation`;
 * - enviada, antes da liberação: nota, resultado, tentativas restantes e acerto/erro por pergunta;
 * - enviada, depois da liberação: também as opções com `correct` e a `explanation` **do snapshot
 *   daquela tentativa**.
 *
 * Os ids de perguntas e opções são os do snapshot (linhas da Academy, que não são apagadas
 * enquanto houver tentativa).
 */
final class QuizAttemptPresenter
{
    /**
     * @param  Collection<string, QuizAttemptAnswer>|null  $answers  respostas por question_id (tentativa enviada)
     * @return array<string, mixed>
     */
    public function detail(QuizAttempt $attempt, QuizStanding $standing, ?Collection $answers = null): array
    {
        $questions = $attempt->snapshot['questions'];

        if ($attempt->status === QuizAttemptStatus::InProgress) {
            return [
                'id' => $attempt->id,
                'attempt_number' => $attempt->attempt_number,
                'max_attempts' => $standing->maxAttempts,
                'status' => $attempt->status->value,
                'questions' => array_map(fn (array $question) => [
                    'id' => $question['id'],
                    'kind' => $question['kind'],
                    'prompt' => $question['prompt'],
                    'options' => array_map(fn (array $option) => ['id' => $option['id'], 'text' => $option['text']], $question['options']),
                ], $questions),
            ];
        }

        $review = $standing->reviewAvailable();
        $answers ??= collect();

        return [
            ...$this->summary($attempt, $standing),
            'question_count' => $attempt->question_count,
            'correct_count' => $attempt->correct_count,
            'questions' => array_map(function (array $question) use ($answers, $review): array {
                $answer = $answers->get($question['id']);
                $item = [
                    'id' => $question['id'],
                    'kind' => $question['kind'],
                    'prompt' => $question['prompt'],
                    'selected_option_ids' => $answer === null ? [] : $answer->selected_option_ids,
                    'is_correct' => $answer !== null && $answer->is_correct,
                ];
                if (! $review) {
                    // Sem gabarito: só os textos das opções, para o jogador reconhecer o que marcou.
                    return [...$item, 'options' => array_map(fn (array $option) => ['id' => $option['id'], 'text' => $option['text']], $question['options'])];
                }

                return [
                    ...$item,
                    'options' => array_map(fn (array $option) => [
                        'id' => $option['id'],
                        'text' => $option['text'],
                        'correct' => $option['correct'],
                    ], $question['options']),
                    'explanation' => $question['explanation'],
                ];
            }, $questions),
        ];
    }

    /**
     * Resumo de uma tentativa (histórico). Aberta: sem nota nem resultado.
     *
     * @return array<string, mixed>
     */
    public function summary(QuizAttempt $attempt, QuizStanding $standing): array
    {
        $submitted = $attempt->status === QuizAttemptStatus::Submitted;

        return [
            'id' => $attempt->id,
            'attempt_number' => $attempt->attempt_number,
            'status' => $attempt->status->value,
            'quiz_revision' => $attempt->quiz_revision,
            'started_at' => $attempt->started_at->toIso8601ZuluString(),
            'submitted_at' => $attempt->submitted_at?->toIso8601ZuluString(),
            'score_percent' => $submitted ? (float) $attempt->score_percent : null,
            'passed' => $submitted ? (bool) $attempt->passed : null,
            'attempts_remaining' => $standing->attemptsRemaining(),
            'review_available' => $submitted && $standing->reviewAvailable(),
        ];
    }
}
