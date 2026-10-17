<?php

namespace App\Modules\Academy\Import;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Arquivos de imagem da Academy no disco `academy` (ADR 0019).
 *
 * O nome é o hash do conteúdo, então o arquivo é imutável e gravar de novo é inofensivo. A
 * gravação passa por `tmp/` e só vira definitiva (rename) depois de conferir o hash; um arquivo
 * definitivo já existente também tem o hash conferido. Gravado antes da transação do banco: se a
 * importação falhar, o arquivo fica órfão (sem linha em academy_media) e `academy:media-prune`
 * o remove depois de 24 horas.
 */
final class MediaStore
{
    public static function pathFor(string $sha256, string $extension): string
    {
        return 'media/'.substr($sha256, 0, 2).'/'.$sha256.'.'.$extension;
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('academy.media_disk'));
    }

    /** Grava a imagem se ainda não existir. Devolve true se gravou um arquivo novo. */
    public function put(InspectedImage $image): bool
    {
        $disk = $this->disk();
        $path = $image->path();

        if ($disk->exists($path)) {
            if (hash('sha256', (string) $disk->get($path)) !== $image->sha256) {
                throw new RuntimeException("Arquivo {$path} existe com conteúdo diferente do hash; verifique o disco academy.");
            }

            return false;
        }

        $temporary = 'tmp/'.Str::uuid()->toString().'.'.$image->extension;
        $disk->put($temporary, $image->contents);
        try {
            if (hash('sha256', (string) $disk->get($temporary)) !== $image->sha256) {
                throw new RuntimeException("Falha ao gravar {$path}: hash diferente após a escrita.");
            }
            $disk->move($temporary, $path);
        } finally {
            if ($disk->exists($temporary)) {
                $disk->delete($temporary);
            }
        }

        return true;
    }
}
