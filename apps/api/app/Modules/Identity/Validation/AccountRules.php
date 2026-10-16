<?php

namespace App\Modules\Identity\Validation;

use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\CountryCodes;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Regras de validação da conta e do perfil, usadas no cadastro e na edição.
 * Espelham as constraints do banco (migrations de users e profiles).
 */
final class AccountRules
{
    /** Constraint do banco => campo do formulário. */
    private const UNIQUE_CONSTRAINTS = [
        'users_email_lower_unique' => 'email',
        'profiles_nickname_lower_unique' => 'nickname',
    ];

    /** @return list<mixed> */
    public static function name(): array
    {
        return ['string', 'max:120'];
    }

    /**
     * E-mail único sem diferenciar maiúsculas; `$ignore` é o próprio usuário, na troca de e-mail.
     *
     * @return list<mixed>
     */
    public static function email(?User $ignore = null): array
    {
        return ['string', 'email:rfc', 'max:254', self::uniqueEmail($ignore)];
    }

    /** @return list<mixed> */
    public static function password(): array
    {
        return ['string', Password::default(), 'confirmed'];
    }

    /**
     * Apelido único sem diferenciar maiúsculas; `$ignore` é o perfil do próprio usuário.
     *
     * @return list<mixed>
     */
    public static function nickname(?Profile $ignore = null): array
    {
        return ['string', 'regex:/^[A-Za-z0-9_.-]{3,24}$/', self::uniqueNickname($ignore)];
    }

    /** @return list<mixed> */
    public static function countryCode(): array
    {
        return ['string', Rule::in(CountryCodes::ALL)];
    }

    /** @return list<mixed> */
    public static function timezone(): array
    {
        return ['string', 'timezone:all'];
    }

    /** @return list<mixed> */
    public static function bio(): array
    {
        return ['string', 'max:500'];
    }

    /**
     * Espaços nas pontas, e-mail em minúsculas e país em maiúsculas, antes da validação.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['email', 'nickname', 'country_code', 'timezone'] as $field) {
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

    /**
     * Corrida: duas requisições passam pela validação com o mesmo e-mail ou apelido e o índice
     * único do banco barra uma delas. A exceção vira o mesmo 422 de campo da validação.
     *
     * @throws ValidationException
     */
    public static function rethrowUniqueViolation(UniqueConstraintViolationException $e): never
    {
        foreach (self::UNIQUE_CONSTRAINTS as $constraint => $field) {
            if (str_contains($e->getMessage(), $constraint)) {
                throw ValidationException::withMessages([
                    $field => [__('validation.unique', ['attribute' => __("validation.attributes.{$field}")])],
                ]);
            }
        }

        throw $e;
    }

    private static function uniqueEmail(?User $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            if (! is_string($value)) {
                return;
            }

            $taken = User::query()->whereEmailIs($value)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->exists();

            if ($taken) {
                $fail('validation.unique')->translate();
            }
        };
    }

    private static function uniqueNickname(?Profile $ignore): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            if (! is_string($value)) {
                return;
            }

            $taken = Profile::query()->whereNicknameIs($value)
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->exists();

            if ($taken) {
                $fail('validation.unique')->translate();
            }
        };
    }
}
