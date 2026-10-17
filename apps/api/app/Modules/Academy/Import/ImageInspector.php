<?php

namespace App\Modules\Academy\Import;

use App\Modules\Academy\Content\ValidationErrors;
use finfo;

/**
 * Valida uma imagem pelo **conteúdo**, nunca pela extensão (ADR 0019, item 9): o tipo detectado
 * pelo libmagic e o decodificado pelo GD precisam concordar e estar na lista permitida (sem SVG),
 * com tamanho e dimensões dentro dos limites de `config/academy.php`.
 */
final class ImageInspector
{
    public const FILENAME_PATTERN = '/^[a-z0-9][a-z0-9._-]*$/';

    public function inspect(string $filename, string $contents, string $location, ValidationErrors $errors): ?InspectedImage
    {
        /** @var array<string, string> $mimes */
        $mimes = config('academy.media.mimes');
        $maxBytes = (int) config('academy.media.max_bytes');
        $maxDimension = (int) config('academy.media.max_dimension');

        $bytes = strlen($contents);
        if ($bytes === 0 || $bytes > $maxBytes) {
            $errors->add($location, 'imagem vazia ou maior que '.intdiv($maxBytes, 1024 * 1024).' MB');

            return null;
        }

        $detected = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents);
        $info = @getimagesizefromstring($contents);
        if (! is_string($detected) || ! isset($mimes[$detected]) || $info === false || $info['mime'] !== $detected) {
            $errors->add($location, 'arquivo não é PNG, JPEG, WebP ou AVIF válido');

            return null;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $expected = $mimes[$detected];
        if ($extension !== $expected && ! ($expected === 'jpg' && $extension === 'jpeg')) {
            $errors->add($location, "extensão .{$extension} não corresponde ao conteúdo ({$detected})");

            return null;
        }

        [$width, $height] = $info;
        if ($width < 1 || $height < 1 || $width > $maxDimension || $height > $maxDimension) {
            $errors->add($location, "dimensões {$width}x{$height} fora do limite de {$maxDimension} px");

            return null;
        }

        return new InspectedImage(hash('sha256', $contents), $detected, $expected, $bytes, $width, $height, $contents);
    }
}
