<?php

namespace App\Modules\Learning\Models;

use App\Modules\Academy\Models\Quiz;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Enums\QuizAttemptStatus;
use Database\Factories\Learning\QuizAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Tentativa de quiz de um jogador (ADR 0018). O snapshot guarda o que o jogador respondeu, com
 * gabarito e explicações, que só saem da API quando a revisão é liberada (QuizAttemptPresenter).
 *
 * Snapshot: `{pass_percent, questions: [{id, kind, prompt, explanation, options: [{id, text, correct}]}]}`.
 *
 * @property string $id
 * @property string $user_id
 * @property string $quiz_id
 * @property int $attempt_number
 * @property int $quiz_revision
 * @property QuizAttemptStatus $status
 * @property array{pass_percent: int, questions: list<array{id: string, kind: string, prompt: string, explanation: string|null, options: list<array{id: string, text: string, correct: bool}>}>} $snapshot
 * @property Carbon $started_at
 * @property Carbon|null $submitted_at
 * @property int|null $question_count
 * @property int|null $correct_count
 * @property string|null $score_percent
 * @property bool|null $passed
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Quiz $quiz
 */
#[UseFactory(QuizAttemptFactory::class)]
class QuizAttempt extends Model
{
    /** @use HasFactory<QuizAttemptFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => QuizAttemptStatus::class,
            'snapshot' => 'array',
            'attempt_number' => 'integer',
            'quiz_revision' => 'integer',
            'started_at' => 'datetime',
            'submitted_at' => 'datetime',
            'question_count' => 'integer',
            'correct_count' => 'integer',
            'score_percent' => 'decimal:2',
            'passed' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return HasMany<QuizAttemptAnswer, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(QuizAttemptAnswer::class, 'attempt_id');
    }
}
