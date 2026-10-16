<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Validation\AccountRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
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
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $data = Validator::make(AccountRules::normalize($input), [
            'name' => ['required', ...AccountRules::name()],
            'email' => ['required', ...AccountRules::email()],
            'password' => ['required', ...AccountRules::password()],
            'nickname' => ['required', ...AccountRules::nickname()],
            'country_code' => ['nullable', ...AccountRules::countryCode()],
            'timezone' => ['nullable', ...AccountRules::timezone()],
        ])->validate();

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
            AccountRules::rethrowUniqueViolation($e);
        }
    }
}
