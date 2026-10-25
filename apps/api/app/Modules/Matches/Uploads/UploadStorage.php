<?php

namespace App\Modules\Matches\Uploads;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Arquivos do disco `matches-uploads` (etapa M3). Os caminhos saem só do UUID do envio:
 * `uploads/{id}.part` enquanto chega e `uploads/{id}.dem` depois de conferido. Nada vindo do
 * cliente (nome do arquivo, número de parte) entra no caminho além do offset calculado.
 *
 * Disco local por enquanto (D-M2); a escrita no offset usa o caminho real do arquivo.
 */
class UploadStorage
{
    public const DIRECTORY = 'uploads';

    public function disk(): Filesystem
    {
        return Storage::disk('matches-uploads');
    }

    public function partPath(string $id): string
    {
        return self::DIRECTORY."/{$id}.part";
    }

    public function demoPath(string $id): string
    {
        return self::DIRECTORY."/{$id}.dem";
    }

    /** Grava `$bytes` a partir de `$offset` no arquivo em montagem (criado na primeira parte). */
    public function writeAt(string $id, int $offset, string $bytes): void
    {
        $this->disk()->makeDirectory(self::DIRECTORY);
        $handle = fopen($this->disk()->path($this->partPath($id)), 'c+b');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo do envio.');
        }

        try {
            if (fseek($handle, $offset) !== 0 || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                throw new RuntimeException('Não foi possível gravar a parte do envio.');
            }
        } finally {
            fclose($handle);
        }
    }

    public function partSize(string $id): int
    {
        $path = $this->partPath($id);

        return $this->disk()->exists($path) ? $this->disk()->size($path) : 0;
    }

    /** Primeiros `$length` bytes do arquivo em montagem. */
    public function head(string $id, int $length): string
    {
        $handle = fopen($this->disk()->path($this->partPath($id)), 'rb');
        if ($handle === false) {
            return '';
        }

        try {
            return (string) fread($handle, $length);
        } finally {
            fclose($handle);
        }
    }

    /** SHA-256 do arquivo em montagem, lido em blocos (não carrega o arquivo na memória). */
    public function sha256(string $id): string
    {
        return (string) hash_file('sha256', $this->disk()->path($this->partPath($id)));
    }

    /** O arquivo conferido passa a `.dem` (rename atômico no mesmo disco). */
    public function promote(string $id): void
    {
        $this->disk()->move($this->partPath($id), $this->demoPath($id));
    }

    /** Apaga o que existir do envio (`.part` e `.dem`). */
    public function delete(string $id): void
    {
        $this->disk()->delete([$this->partPath($id), $this->demoPath($id)]);
    }

    /** Espaço livre no volume do disco, em bytes. */
    public function freeBytes(): int
    {
        $this->disk()->makeDirectory(self::DIRECTORY);

        return (int) disk_free_space($this->disk()->path(self::DIRECTORY));
    }
}
