<?php

namespace App\Modules\Academy\Content;

/**
 * JSON canônico do conteúdo de uma aula: chaves de objetos em ordem alfabética, sem escapar
 * Unicode nem barras. O mesmo conteúdo gera sempre os mesmos bytes, e portanto o mesmo
 * `content_hash` (ADR 0016, ADR 0017).
 */
final class CanonicalJson
{
    /** @param  array<mixed>  $value */
    public static function encode(array $value): string
    {
        return json_encode(self::sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @param  array<mixed>  $value */
    public static function hash(array $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sort(...), $value);
    }
}
