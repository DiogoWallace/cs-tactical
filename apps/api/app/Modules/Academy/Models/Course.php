<?php

namespace App\Modules\Academy\Models;

use App\Modules\Academy\Enums\CourseLevel;
use App\Modules\Academy\Enums\PublicationStatus;
use Database\Factories\Academy\CourseFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Curso da Academy (ADR 0015). Gravado só pela importação (`academy:import`): nenhum campo é
 * preenchível em massa.
 *
 * @property string $id
 * @property string $key
 * @property string $slug
 * @property string $title
 * @property string $summary
 * @property CourseLevel $level
 * @property PublicationStatus $status
 * @property int $position
 * @property string|null $cover_media_id
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Media|null $coverMedia
 */
#[UseFactory(CourseFactory::class)]
class Course extends Model
{
    /** @use HasFactory<CourseFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level' => CourseLevel::class,
            'status' => PublicationStatus::class,
            'position' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return HasMany<CourseModule, $this> */
    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class);
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /** @return BelongsTo<Media, $this> */
    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /**
     * Cursos públicos (regra R2): o curso não tem ancestral, então basta estar publicado.
     *
     * @param  Builder<Course>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('courses.status', PublicationStatus::Published->value);
    }
}
