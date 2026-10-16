<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Validation\AccountRules;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /api/v1/me/password — exige a senha atual. */
class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->can('updateCredentials', $user) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', ...AccountRules::password(), 'different:current_password'],
        ];
    }
}
