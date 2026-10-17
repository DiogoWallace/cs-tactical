<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Validation\AccountRules;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/v1/me/email — novo endereço e a senha atual. */
class RequestEmailChangeRequest extends FormRequest
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
        /** @var User $user */
        $user = $this->user();

        return [
            'email' => ['required', ...AccountRules::email($user), 'not_in:'.$user->email],
            // bail: sem ele, uma lista no lugar da senha chegava ao password_verify() (erro 500).
            'current_password' => ['bail', 'required', 'string', 'current_password:web'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.not_in' => 'Informe um e-mail diferente do atual.'];
    }

    protected function prepareForValidation(): void
    {
        $this->replace(AccountRules::normalize($this->all()));
    }
}
