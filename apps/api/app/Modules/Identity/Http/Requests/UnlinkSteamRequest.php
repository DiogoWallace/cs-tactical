<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/** DELETE /api/v1/me/steam — exige a senha atual (decisão S2), como trocar e-mail e senha. */
class UnlinkSteamRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->can('manageSteamLink', $user) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // bail: uma lista no lugar da senha não chega ao password_verify() (F2 da Fase 1).
            'current_password' => ['bail', 'required', 'string', 'current_password:web'],
        ];
    }
}
