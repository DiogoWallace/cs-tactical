<?php

namespace App\Modules\Matches\Actions;

use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Exceptions\MatchUploadFailed;
use App\Modules\Matches\Jobs\ExpireMatchUpload;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\UploadStorage;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

            $bytes = $body();
            if (strlen($bytes) !== $expected) {
                throw MatchUploadFailed::partSizeInvalid();
            }

            // P5: integridade da parte.
            if (preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1 || ! hash_equals($sha256, hash('sha256', $bytes))) {
                throw MatchUploadFailed::partCorrupted();
            }

            $this->storage->writeAt($locked->id, ($number - 1) * $locked->part_size, $bytes);

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
}
