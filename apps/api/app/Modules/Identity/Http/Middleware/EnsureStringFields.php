<?php

namespace App\Modules\Identity\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Login e cadastro: os campos de texto chegam como texto antes de qualquer código os usar.
 *
 * O limitador de login e o controller de cadastro do Fortify (que põe o e-mail em minúsculas)
 * leem a entrada antes da validação. Uma lista ou objeto no lugar de uma string virava erro
 * 500; agora é o mesmo 422 de validação dos demais campos.
 */
class EnsureStringFields
{
    private const FIELDS = [
        'name',
        'email',
        'password',
        'password_confirmation',
        'nickname',
        'country_code',
        'timezone',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $errors = [];

        foreach (self::FIELDS as $field) {
            $value = $request->input($field);

            if ($value !== null && ! is_string($value)) {
                $errors[$field] = [__('validation.string', ['attribute' => $field])];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $next($request);
    }
}
