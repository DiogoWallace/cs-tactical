<?php

namespace App\Modules\Learning\Http\Resources;

use App\Modules\Learning\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resposta da matrícula (PUT .../enrollment). Curso pelo slug; sem ids.
 *
 * @mixin Enrollment
 */
class EnrollmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'course' => $this->course->slug,
            'enrolled_at' => $this->enrolled_at->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
        ];
    }
}
