<?php

namespace App\Modules\Academy\Models;

use App\Modules\Academy\Enums\PublicationStatus;
use Database\Factories\Academy\LessonFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Aula (ADR 0015, ADR 0016). `content` = {"version": n, "blocks": [...]}, validado pelos schemas
 * do módulo (App\Modules\Academy\Content); `content_hash` identifica mudanças (ADR 0017).
 *
 * @property string $id
 * @property string $course_id
 * @property string $module_id
 * @property string $key
 * @property string $slug
 * @property string $title
 * @property string|null $summary
 * @property int $position
 * @property int|null $estimated_minutes
 * @property array{version: int, blocks: list<array{type: string, data: array<string, mixed>}>} $content
 * @property int $content_version
 * @property string $content_hash
 * @property PublicationStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Course $course
 * @property-read CourseModule $module
 */
#[UseFactory(LessonFactory::class)]
class Lesson extends Model
{
    /** @use HasFactory<LessonFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'position' => 'integer',
            'estimated_minutes' => 'integer',
            'content' => 'array',
            'content_version' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<CourseModule, $this> */
    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    /** @return HasMany<Quiz, $this> */
    public function quizzes(): HasMany
    {
        return $this->hasMany(Quiz::class);
    }

    /** @return HasOne<Quiz, $this> */
    public function activeQuiz(): HasOne
    {
        return $this->hasOne(Quiz::class)->whereNull('retired_at');
    }

    /**
     * Aulas públicas (regra R2): a aula, o módulo **e** o curso publicados. Rascunho ou
     * arquivado em qualquer nível esconde a aula.
     *
     * @param  Builder<Lesson>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $published = PublicationStatus::Published->value;

        $query->where('lessons.status', $published)
            ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('course_modules')
                ->whereColumn('course_modules.id', 'lessons.module_id')
                ->where('course_modules.status', $published))
            ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('courses')
                ->whereColumn('courses.id', 'lessons.course_id')
                ->where('courses.status', $published));
    }
}
