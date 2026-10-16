<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Enums\PlatformAbility;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

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
            'abilities' => $this->effectiveAbilities(),
            'profile' => new ProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Capacidades de plataforma que os Gates concedem a este usuário agora (ADR 0012).
     *
     * Serve só para o frontend mostrar ou esconder elementos. A autorização de cada ação
     * continua nos Gates e Policies do backend: ter o nome aqui não libera nada.
     *
     * @return list<string>
     */
    private function effectiveAbilities(): array
    {
        $gate = Gate::forUser($this->resource);

        return array_values(array_map(
            fn (PlatformAbility $ability) => $ability->value,
            array_filter(PlatformAbility::cases(), fn (PlatformAbility $ability) => $gate->allows($ability->value)),
        ));
    }
}
