<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\UserSteamAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Conta Steam vinculada, vista só pelo próprio usuário (decisão S3: SteamID64 completo só para ele).
 *
 * @mixin UserSteamAccount
 */
class SteamAccountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'steam_id' => $this->steam_id,
            'linked_at' => $this->linked_at->toIso8601ZuluString(),
        ];
    }
}
