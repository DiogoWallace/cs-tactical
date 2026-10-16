<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\CountryCodes;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

/**
 * Cadastro (Fortify `CreatesNewUsers`). Cria a conta e o perfil numa única transação.
 *
 * - Só os campos abaixo são lidos. `platform_role`, `email_verified_at` e qualquer outro
 *   campo enviado pelo cliente são ignorados; o papel inicial é sempre `user` (padrão do model
 *   e do banco).
 * - E-mail e apelido são únicos sem diferenciar maiúsculas, como as constraints do banco.
 *   Se dois cadastros simultâneos passarem pela validação, a violação de unicidade do banco
 *   vira o mesmo erro 422 de campo.
 */
class CreateNewUser implements CreatesNewUsers
{
    /** Constraint do banco => campo do formulário. */
    private const UNIQUE_CONSTRAINTS = [
        'users_email_lower_unique' => 'email',
        'profiles_nickname_lower_unique' => 'nickname',
    ];

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $data = Validator::make($this->normalize($input), $this->rules())->validate();

        try {
            return DB::transaction(function () use ($data): User {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);

                $user->profile()->create([
                    'nickname' => $data['nickname'],
                    'country_code' => $data['country_code'] ?? null,
                    'timezone' => $data['timezone'] ?? 'UTC',
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException $e) {
            foreach (self::UNIQUE_CONSTRAINTS as $constraint => $field) {
                if (str_contains($e->getMessage(), $constraint)) {
                    throw ValidationException::withMessages([
                        $field => [__('validation.unique', ['attribute' => $field])],
                    ]);
                }
            }

            throw $e;
        }
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', $this->uniqueEmail()],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'nickname' => ['required', 'string', 'regex:/^[A-Za-z0-9_.-]{3,24}$/', $this->uniqueNickname()],
            'country_code' => ['nullable', 'string', Rule::in(CountryCodes::ALL)],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function normalize(array $input): array
    {
        foreach (['email', 'nickname', 'country_code'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }

        if (is_string($input['email'] ?? null)) {
            $input['email'] = User::normalizeEmail($input['email']);
        }

        if (is_string($input['country_code'] ?? null)) {
            $input['country_code'] = strtoupper($input['country_code']);
        }

        return $input;
    }

    private function uniqueEmail(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && User::query()->whereEmailIs($value)->exists()) {
                $fail('validation.unique')->translate();
            }
        };
    }

    private function uniqueNickname(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && Profile::query()->whereNicknameIs($value)->exists()) {
                $fail('validation.unique')->translate();
            }
        };
    }
}
