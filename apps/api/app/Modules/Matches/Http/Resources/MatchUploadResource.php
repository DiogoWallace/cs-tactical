<?php

namespace App\Modules\Matches\Http\Resources;

use App\Modules\Matches\Models\MatchUpload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Envio de demo, visto pelo dono. Lista fechada: nada de caminho no disco nem de hash.
 *
 * @mixin MatchUpload
 */
class MatchUploadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'name' => $this->name,
            'size_bytes' => $this->size_bytes,
            'part_size' => $this->part_size,
            'parts_total' => $this->parts_total,
            'parts_received' => $this->parts_received,
            'rejection_code' => $this->rejection_code,
            'expires_at' => $this->expires_at->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
