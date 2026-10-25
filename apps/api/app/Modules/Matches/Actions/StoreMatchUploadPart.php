<?php

namespace App\Modules\Matches\Actions;

use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Exceptions\MatchUploadFailed;
use App\Modules\Matches\Exceptions\UploadStorageFailed;
use App\Modules\Matches\Jobs\ExpireMatchUpload;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\UploadStorage;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Uma parte do envio (regras P2 a P5 de docs/training/mvp-m3-envio-de-demos.md; P1 fica com a
 * rota e a busca por posse). A linha do envio fica travada: duas partes simultâneas do mesmo envio
 * são serializadas. O corpo só é lido depois de conferir número e tamanho declarado.
 */
class StoreMatchUploadPart
{
    public function __construct(private readonly UploadStorage $storage) {}

    /**
     * @param  Closure(): string  $body  lê o corpo da requisição
     *
     * @throws MatchUploadFailed
     */
    public function handle(MatchUpload $upload, int $number, ?int $declaredLength, string $sha256, Closure $body): MatchUpload
    {
        $stored = DB::transaction(function () use ($upload, $number, $declaredLength, $sha256, $body): MatchUpload {
            $locked = MatchUpload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            // P2: aberto e dentro do prazo.
            if ($locked->status !== MatchUploadStatus::Uploading || $locked->expires_at->isPast()) {
                throw MatchUploadFailed::notOpen();
            }

            // P3: a última parte de novo, com o mesmo hash, é uma retentativa (nada é reescrito).
            if ($number === $locked->parts_received && $locked->last_part_sha256 !== null && hash_equals($locked->last_part_sha256, $sha256)) {
                return $locked;
            }
            if ($number !== $locked->parts_received + 1) {
                throw MatchUploadFailed::partOutOfOrder();
            }

            // P4: o tamanho declarado precisa bater antes de ler o corpo.
            $expected = $locked->expectedPartSize($number);
            if ($declaredLength !== $expected) {
                throw MatchUploadFailed::partSizeInvalid();
            }

            // P6 (#54, D2): espaço para a parte e a reserva, antes de ler o corpo. É só prevenção:
            // outro processo pode ocupar o disco entre esta conferência e a gravação, tratada abaixo.
            try {
                $free = $this->storage->freeBytes();
            } catch (UploadStorageFailed $exception) {
                throw $this->unavailable($exception);
            }
            if ($free < $expected + (int) config('matches.disk_reserve_bytes')) {
                throw MatchUploadFailed::storageUnavailable((int) config('matches.storage_retry_after_seconds'));
            }

            $bytes = $body();
            if (strlen($bytes) !== $expected) {
                throw MatchUploadFailed::partSizeInvalid();
            }

            // P5: integridade da parte.
            if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1 || ! hash_equals($sha256, hash('sha256', $bytes))) {
                throw MatchUploadFailed::partCorrupted();
            }

            // Gravação que falha (inclusive no meio) não conta a parte: a transação desfaz tudo e a
            // retentativa grava de novo no mesmo trecho, sobrescrevendo o que tiver ficado.
            try {
                $this->storage->writeAt($locked->id, ($number - 1) * $locked->part_size, $bytes);
            } catch (UploadStorageFailed $exception) {
                throw $this->unavailable($exception);
            }

            $now = Carbon::now();
            $locked->forceFill([
                'parts_received' => $number,
                'last_part_sha256' => $sha256,
                'last_activity_at' => $now,
                'expires_at' => $now->copy()->addMinutes((int) config('matches.idle_minutes')),
            ])->save();

            return $locked;
        });

        ExpireMatchUpload::scheduleFor($stored);

        return $stored;
    }

    private function unavailable(UploadStorageFailed $exception): MatchUploadFailed
    {
        Log::warning('matches.uploads.storage-failed', ['operation' => $exception->operation, 'upload' => $exception->uploadId]);

        return MatchUploadFailed::storageUnavailable((int) config('matches.storage_retry_after_seconds'));
    }
}
