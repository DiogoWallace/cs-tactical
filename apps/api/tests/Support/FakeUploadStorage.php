<?php

namespace Tests\Support;

use App\Modules\Matches\Exceptions\UploadStorageFailed;
use App\Modules\Matches\Uploads\UploadStorage;

/**
 * Disco `matches-uploads` com espaço livre fixo e falhas programadas (#54). Usa o disco real (ou o
 * falso do `Storage::fake`) para tudo que não estiver programado, então os arquivos continuam
 * conferíveis. O espaço livre fixo vale também nos processos dos testes de concorrência, sem
 * precisar encher o disco de verdade.
 */
class FakeUploadStorage extends UploadStorage
{
    /** @var array<string, int> operação => quantas próximas chamadas falham */
    private array $failures = [];

    /**
     * @param  int|null  $free  espaço livre informado; null usa o do disco
     * @param  int|null  $partialBytes  numa falha de `writeAt`, quantos bytes chegam a ser gravados antes
     */
    public function __construct(public ?int $free = null, public ?int $partialBytes = null) {}

    /** As próximas `$times` chamadas de `$operation` (writeAt, partSize, head, sha256, promote, freeBytes, delete) falham. */
    public function failNext(string $operation, int $times = 1): static
    {
        $this->failures[$operation] = $times;

        return $this;
    }

    public function writeAt(string $id, int $offset, string $bytes): void
    {
        if ($this->fails('writeAt')) {
            if ($this->partialBytes !== null) {
                parent::writeAt($id, $offset, substr($bytes, 0, $this->partialBytes));
            }

            throw new UploadStorageFailed('gravar', $id);
        }

        parent::writeAt($id, $offset, $bytes);
    }

    public function partSize(string $id): int
    {
        return $this->fails('partSize') ? throw new UploadStorageFailed('medir', $id) : parent::partSize($id);
    }

    public function head(string $id, int $length): string
    {
        return $this->fails('head') ? throw new UploadStorageFailed('ler', $id) : parent::head($id, $length);
    }

    public function sha256(string $id): string
    {
        return $this->fails('sha256') ? throw new UploadStorageFailed('calcular o hash de', $id) : parent::sha256($id);
    }

    public function promote(string $id): void
    {
        if ($this->fails('promote')) {
            throw new UploadStorageFailed('concluir', $id);
        }

        parent::promote($id);
    }

    public function delete(string $id): void
    {
        // Como a implementação real numa falha: não lança e não apaga.
        if (! $this->fails('delete')) {
            parent::delete($id);
        }
    }

    public function freeBytes(): int
    {
        if ($this->fails('freeBytes')) {
            throw new UploadStorageFailed('medir o espaço para', '-');
        }

        return $this->free ?? parent::freeBytes();
    }

    private function fails(string $operation): bool
    {
        if (($this->failures[$operation] ?? 0) < 1) {
            return false;
        }
        $this->failures[$operation]--;

        return true;
    }
}
