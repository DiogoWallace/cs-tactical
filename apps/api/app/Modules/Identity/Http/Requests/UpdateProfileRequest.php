<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Validation\AccountRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/v1/me/profile — atualização parcial: só os campos enviados mudam.
 * `country_code` e `bio` aceitam null para limpar o valor.
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        $profile = $this->profile();

        return $profile !== null && $this->user()?->can('update', $profile) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', ...AccountRules::name()],
            'nickname' => ['sometimes', 'required', ...AccountRules::nickname($this->profile())],
            'country_code' => ['sometimes', 'nullable', ...AccountRules::countryCode()],
            'timezone' => ['sometimes', 'required', ...AccountRules::timezone()],
            'bio' => ['sometimes', 'nullable', ...AccountRules::bio()],
        ];
    }

    public function profile(): ?Profile
    {
        /** @var User|null $user */
        $user = $this->user();

        return $user?->profile;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(AccountRules::normalize($this->all()));
    }
}
