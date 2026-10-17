<?php

namespace App\Modules\Learning\Models;

use App\Modules\Academy\Models\Course;
use App\Modules\Identity\Models\User;
use Database\Factories\Learning\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Matrícula de um jogador num curso (ADR 0017). Uma por jogador e curso; criada pela matrícula
 * explícita ou pela primeira conclusão de aula (auto-matrícula, R8). Sem cancelamento (P4).
 *
 * @property string $id
 * @property string $user_id
 * @property string $course_id
 * @property Carbon $enrolled_at
 * @property Carbon|null $completed_at
 * @property Carbon $last_activity_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Course $course
 */
#[UseFactory(EnrollmentFactory::class)]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }
}
