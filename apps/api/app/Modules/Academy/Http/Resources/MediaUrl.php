<?php

namespace App\Modules\Academy\Http\Resources;

use App\Modules\Academy\Models\Media;

/**
 * URL pública de uma imagem: relativa à origem (as imagens passam pelo mesmo proxy que a API,
 * ADR 0019, item 10) e só com o nome do arquivo, nunca o caminho no disco.
 */
final class MediaUrl
{
    public static function of(Media $media): string
    {
        return route('v1.academy.media.show', ['file' => basename($media->path)], absolute: false);
    }
}
