<?php

namespace App\Modules\Learning\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * `uuid[]` do PostgreSQL ⇄ lista de strings. Só aceita UUIDs (nada de texto livre entra no literal
 * do array).
 *
 * @implements CastsAttributes<list<string>, array<array-key, mixed>>
 */
final class UuidArray implements CastsAttributes
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '{}') {
            return [];
        }

        return array_values(array_filter(explode(',', trim($value, '{}'))));
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Esperada uma lista de UUIDs.');
        }
        foreach ($value as $id) {
            if (! is_string($id) || preg_match(self::UUID, $id) !== 1) {
                throw new InvalidArgumentException('UUID inválido.');
            }
        }

        return '{'.implode(',', $value).'}';
    }
}
