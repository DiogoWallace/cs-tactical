<?php

namespace App\Modules\Academy\Models;

use App\Modules\Academy\Enums\PublicationStatus;
use Database\Factories\Academy\CourseModuleFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Módulo de um curso (ADR 0015).
 *
 * @property string $id
 * @property string $course_id
 * @property string $key
 * @property string $slug
 * @property string $title
 * @property string|null $summary
 * @property int $position
 * @property PublicationStatus $status
 * @property Carbon|null $published_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Course $course
 */
#[UseFactory(CourseModuleFactory::class)]
class CourseModule extends Model
{
    /** @use HasFactory<CourseModuleFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'position' => 'integer',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return HasMany<Lesson, $this> */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'module_id');
    }

    /**
     * Módulos públicos (regra R2): o módulo **e** o curso publicados.
     *
     * @param  Builder<CourseModule>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('course_modules.status', PublicationStatus::Published->value)
            ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('courses')
                ->whereColumn('courses.id', 'course_modules.course_id')
                ->where('courses.status', PublicationStatus::Published->value));
    }
}
