<?php

namespace App\Modules\Academy\Content;

/**
 * Leitura estrita de campos de um mapa vindo de YAML: tipo exato, limites e nenhum campo extra.
 * Cada método registra o erro em `$errors` e devolve null quando o valor é inválido.
 */
final class Fields
{
    public const KEY_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $allowed
     */
    public static function rejectUnknown(array $data, array $allowed, string $location, ValidationErrors $errors): void
    {
        foreach (array_keys($data) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                $errors->add(self::at($location, (string) $key), 'campo desconhecido');
            }
        }
    }

    /** @param  array<array-key, mixed>  $data */
    public static function string(array $data, string $key, string $location, ValidationErrors $errors, int $min = 1, int $max = 255, bool $required = true): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            if ($required) {
                $errors->add(self::at($location, $key), 'obrigatório');
            }

            return null;
        }
        $value = $data[$key];
        if (! is_string($value)) {
            $errors->add(self::at($location, $key), 'precisa ser texto');

            return null;
        }
        $length = mb_strlen(trim($value));
        if ($length < $min || mb_strlen($value) > $max) {
            $errors->add(self::at($location, $key), "precisa ter de {$min} a {$max} caracteres");

            return null;
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $data */
    public static function key(array $data, string $key, string $location, ValidationErrors $errors): ?string
    {
        $value = self::string($data, $key, $location, $errors, 1, 80);
        if ($value !== null && preg_match(self::KEY_PATTERN, $value) !== 1) {
            $errors->add(self::at($location, $key), 'use letras minúsculas, números e hífens (ex.: counter-strafe)');

            return null;
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $data */
    public static function int(array $data, string $key, string $location, ValidationErrors $errors, int $min, int $max, bool $required = true): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            if ($required) {
                $errors->add(self::at($location, $key), 'obrigatório');
            }

            return null;
        }
        $value = $data[$key];
        if (! is_int($value) || $value < $min || $value > $max) {
            $errors->add(self::at($location, $key), "precisa ser um número inteiro de {$min} a {$max}");

            return null;
        }

        return $value;
    }

    /** @param  array<array-key, mixed>  $data */
    public static function bool(array $data, string $key, string $location, ValidationErrors $errors, ?bool $default = null): ?bool
    {
        if (! array_key_exists($key, $data)) {
            if ($default === null) {
                $errors->add(self::at($location, $key), 'obrigatório');
            }

            return $default;
        }
        if (! is_bool($data[$key])) {
            $errors->add(self::at($location, $key), 'precisa ser true ou false');

            return null;
        }

        return $data[$key];
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $choices
     */
    public static function choice(array $data, string $key, array $choices, string $location, ValidationErrors $errors): ?string
    {
        $value = self::string($data, $key, $location, $errors, 1, 32);
        if ($value !== null && ! in_array($value, $choices, true)) {
            $errors->add(self::at($location, $key), 'use um destes valores: '.implode(', ', $choices));

            return null;
        }

        return $value;
    }

    /**
     * Lista de mapas (ex.: blocos, perguntas).
     *
     * @param  array<array-key, mixed>  $data
     * @return list<array<array-key, mixed>>|null
     */
    public static function listOfMaps(array $data, string $key, string $location, ValidationErrors $errors, int $min, int $max): ?array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            $errors->add(self::at($location, $key), 'precisa ser uma lista');

            return null;
        }
        if (count($value) < $min || count($value) > $max) {
            $errors->add(self::at($location, $key), "precisa ter de {$min} a {$max} itens");

            return null;
        }
        foreach ($value as $index => $item) {
            if (! is_array($item) || array_is_list($item) && $item !== []) {
                $errors->add(self::at($location, "{$key}[{$index}]"), 'precisa ser um mapa de campos');

                return null;
            }
        }

        /** @var list<array<array-key, mixed>> $value */
        return $value;
    }

    /**
     * Local de um campo. `$location` é "arquivo" ou "arquivo: caminho"; o resultado é sempre
     * "arquivo: caminho.campo" (ex.: `aula.yaml: content.blocks[2].alt`).
     */
    public static function at(string $location, string $field): string
    {
        if (! str_contains($location, ': ')) {
            return "{$location}: {$field}";
        }

        return str_starts_with($field, '[') ? $location.$field : "{$location}.{$field}";
    }
}
