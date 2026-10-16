<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O usuário autenticado, como visto por ele mesmo.
 *
 * Lista explícita de campos: senha, remember_token e qualquer coluna futura só aparecem
 * se forem adicionadas aqui.
 *
 * @mixin User
 */
class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            // Novo e-mail aguardando confirmação (ADR 0011), ou null.
            'pending_email' => $this->pending_email,
            'platform_role' => $this->platform_role->value,
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
