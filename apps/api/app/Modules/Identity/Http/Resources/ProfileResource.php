<?php

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Profile
 */
class ProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'nickname' => $this->nickname,
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'bio' => $this->bio,
        ];
    }
}
