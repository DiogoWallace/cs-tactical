<?php

namespace App\Modules\Learning\Models;

use App\Modules\Academy\Models\Lesson;
use App\Modules\Identity\Models\User;
use App\Modules\Learning\Enums\LessonProgressStatus;
use Database\Factories\Learning\LessonProgressFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Progresso de um jogador numa aula (ADR 0017). Pertence à aula, não à posição dela; a conclusão
 * é histórica e guarda o hash do conteúdo daquele momento.
 *
 * @property string $id
 * @property string $user_id
 * @property string $lesson_id
 * @property string $course_id
 * @property LessonProgressStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $completed_at
 * @property string|null $completed_content_hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Lesson $lesson
 */
#[UseFactory(LessonProgressFactory::class)]
class LessonProgress extends Model
{
    /** @use HasFactory<LessonProgressFactory> */
    use HasFactory, HasUuids;

    protected $table = 'lesson_progress';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => LessonProgressStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }
}
