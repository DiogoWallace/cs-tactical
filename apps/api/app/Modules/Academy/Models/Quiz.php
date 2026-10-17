<?php

namespace App\Modules\Academy\Models;

use Database\Factories\Academy\QuizFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Quiz de uma aula (ADR 0018). Segue a visibilidade da aula. Um quiz substituído por outro (nova
 * `key`, decisão P2) é aposentado e continua no histórico.
 *
 * @property string $id
 * @property string $lesson_id
 * @property string $key
 * @property string $title
 * @property int $max_attempts
 * @property int $pass_percent
 * @property int $revision
 * @property Carbon|null $retired_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Lesson $lesson
 */
#[UseFactory(QuizFactory::class)]
class Quiz extends Model
{
    /** @use HasFactory<QuizFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'max_attempts' => 3,
        'pass_percent' => 70,
        'revision' => 1,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'max_attempts' => 'integer',
            'pass_percent' => 'integer',
            'revision' => 'integer',
            'retired_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return HasMany<QuizQuestion, $this> */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class);
    }
}
