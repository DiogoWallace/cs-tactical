<?php

namespace App\Modules\Learning\Models;

use App\Modules\Learning\Casts\UuidArray;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Resposta a uma pergunta numa tentativa enviada (ADR 0018).
 *
 * @property string $id
 * @property string $attempt_id
 * @property string $question_id
 * @property list<string> $selected_option_ids
 * @property bool $is_correct
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class QuizAttemptAnswer extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'selected_option_ids' => UuidArray::class,
            'is_correct' => 'boolean',
        ];
    }

    /** @return BelongsTo<QuizAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(QuizAttempt::class, 'attempt_id');
    }
}
