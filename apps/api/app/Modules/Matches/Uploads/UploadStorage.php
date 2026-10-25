<?php

namespace App\Modules\Matches\Uploads;

use App\Modules\Matches\Exceptions\UploadStorageFailed;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Arquivos do disco `matches-uploads` (etapa M3). Os caminhos saem só do UUID do envio:
 * `uploads/{id}.part` enquanto chega e `uploads/{id}.dem` depois de conferido. Nada vindo do
 * cliente (nome do arquivo, número de parte) entra no caminho além do offset calculado.
 *
 * Toda falha do disco (inclusive avisos do PHP, como o de disco cheio no `fwrite`) sai como
 * `UploadStorageFailed`, nunca como um valor vazio que pareça conteúdo do jogador (#54). A exceção
 * é `delete()`: remover é melhor esforço, e o `matches:prune-uploads` apaga o que sobrar.
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

    /**
     * Grava `$bytes` a partir de `$offset` no arquivo em montagem (criado na primeira parte).
     * Uma gravação interrompida pode deixar parte dos bytes no lugar; a retentativa da mesma parte
     * grava no mesmo trecho e os sobrescreve.
     */
    public function writeAt(string $id, int $offset, string $bytes): void
    {
        $this->guard('gravar', $id, function () use ($id, $offset, $bytes): void {
            $this->disk()->makeDirectory(self::DIRECTORY);
            $handle = fopen($this->disk()->path($this->partPath($id)), 'c+b');
            if ($handle === false) {
                throw new RuntimeException('fopen');
            }

            try {
                if (fseek($handle, $offset) !== 0 || fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                    throw new RuntimeException('fwrite');
                }
            } finally {
                fclose($handle);
            }
        });
    }

    /** Tamanho do arquivo em montagem; 0 se ele não existe. */
    public function partSize(string $id): int
    {
        return $this->guard('medir', $id, function () use ($id): int {
            $path = $this->partPath($id);

            return $this->disk()->exists($path) ? $this->disk()->size($path) : 0;
        });
    }

    /** Primeiros `$length` bytes do arquivo em montagem. */
    public function head(string $id, int $length): string
    {
        return $this->guard('ler', $id, function () use ($id, $length): string {
            $handle = fopen($this->disk()->path($this->partPath($id)), 'rb');
            if ($handle === false) {
                throw new RuntimeException('fopen');
            }

            try {
                $bytes = fread($handle, $length);
                if ($bytes === false) {
                    throw new RuntimeException('fread');
                }

                return $bytes;
            } finally {
                fclose($handle);
            }
        });
    }

    /** SHA-256 do arquivo em montagem, lido em blocos (não carrega o arquivo na memória). */
    public function sha256(string $id): string
    {
        return $this->guard('calcular o hash de', $id, function () use ($id): string {
            $hash = hash_file('sha256', $this->disk()->path($this->partPath($id)));
            if ($hash === false) {
                throw new RuntimeException('hash_file');
            }

            return $hash;
        });
    }

    /** O arquivo conferido passa a `.dem` (rename atômico no mesmo disco). */
    public function promote(string $id): void
    {
        $this->guard('concluir', $id, function () use ($id): void {
            $this->disk()->move($this->partPath($id), $this->demoPath($id));
            if (! $this->disk()->exists($this->demoPath($id))) {
                throw new RuntimeException('move');
            }
        });
    }

    /**
     * Desfaz uma promoção cujo registro no banco não chegou a ser gravado: para um envio que ainda
     * está aberto, um `.dem` sem `.part` volta a ser o arquivo em montagem.
     */
    public function reclaimPromoted(string $id): void
    {
        $this->guard('recuperar', $id, function () use ($id): void {
            if (! $this->disk()->exists($this->partPath($id)) && $this->disk()->exists($this->demoPath($id))) {
                $this->disk()->move($this->demoPath($id), $this->partPath($id));
            }
        });
    }

    /** Apaga o que existir do envio (`.part` e `.dem`); uma falha fica para o `matches:prune-uploads`. */
    public function delete(string $id): void
    {
        try {
            $this->disk()->delete([$this->partPath($id), $this->demoPath($id)]);
        } catch (Throwable $exception) {
            Log::warning('matches.uploads.delete-failed', ['upload' => $id, 'error' => $exception::class]);
        }
    }

    /** Espaço livre no volume do disco, em bytes. */
    public function freeBytes(): int
    {
        return $this->guard('medir o espaço para', '-', function (): int {
            $this->disk()->makeDirectory(self::DIRECTORY);
            $free = disk_free_space($this->disk()->path(self::DIRECTORY));
            if ($free === false) {
                throw new RuntimeException('disk_free_space');
            }

            return (int) $free;
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function guard(string $name, string $id, Closure $operation): mixed
    {
        try {
            return $operation();
        } catch (UploadStorageFailed $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UploadStorageFailed($name, $id, $exception);
        }
    }
}
