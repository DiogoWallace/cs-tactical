<?php

namespace App\Modules\Academy\Models;

use App\Modules\Academy\Enums\QuestionKind;
use Database\Factories\Academy\QuizQuestionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Pergunta de quiz (ADR 0018). Aposentada (`retired_at`), não apagada, se o quiz já teve
 * tentativa.
 *
 * @property string $id
 * @property string $quiz_id
 * @property string $key
 * @property QuestionKind $kind
 * @property string $prompt
 * @property string|null $explanation
 * @property int $position
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Quiz $quiz
 */
#[UseFactory(QuizQuestionFactory::class)]
class QuizQuestion extends Model
{
    /** @use HasFactory<QuizQuestionFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => QuestionKind::class,
            'position' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Quiz, $this> */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /** @return HasMany<QuizOption, $this> */
    public function options(): HasMany
    {
        return $this->hasMany(QuizOption::class, 'question_id');
    }
}
