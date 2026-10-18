<?php

namespace App\Modules\Learning\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Models\Enrollment;
use App\Modules\Learning\Support\PublicContentLocks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Matrícula num curso público (R7). Idempotente e segura com requisições simultâneas: o único
 * `(user_id, course_id)` decide, via INSERT ... ON CONFLICT DO NOTHING, e quem chega depois recebe
 * a matrícula existente. Repetir não escreve nada.
 */
class EnrollInCourse
{
    public function __construct(private readonly PublicContentLocks $locks) {}

    /** @return array{0: Enrollment, 1: bool}|null [matrícula, criada agora]; null se o curso não é público */
    public function handle(User $user, string $courseSlug): ?array
    {
        return DB::transaction(function () use ($user, $courseSlug): ?array {
            $course = $this->locks->course($courseSlug);
            if ($course === null) {
                return null;
            }

            $created = self::ensure($user->getKey(), $course->id, Carbon::now());
            $enrollment = Enrollment::query()
                ->where('user_id', $user->getKey())
                ->where('course_id', $course->id)
                ->firstOrFail();
            $enrollment->setRelation('course', $course);

            return [$enrollment, $created];
        });
    }

    /**
     * Cria a matrícula se não existir. Devolve true se criou. Dentro de uma transação.
     *
     * A consulta antes evita qualquer escrita no caso comum (já matriculado); o ON CONFLICT cobre a
     * corrida entre duas requisições que passaram juntas pela consulta.
     */
    public static function ensure(string $userId, string $courseId, Carbon $now): bool
    {
        $exists = Enrollment::query()->where('user_id', $userId)->where('course_id', $courseId)->exists();
        if ($exists) {
            return false;
        }

        return Enrollment::query()->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'user_id' => $userId,
            'course_id' => $courseId,
            'enrolled_at' => $now,
            'last_activity_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}
