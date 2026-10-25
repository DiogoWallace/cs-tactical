<?php

namespace App\Modules\Matches\Actions;

use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Exceptions\MatchUploadFailed;
use App\Modules\Matches\Exceptions\UploadStorageFailed;
use App\Modules\Matches\Jobs\ExpireMatchUpload;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\DemoSignature;
use App\Modules\Matches\Uploads\UploadStorage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Conclusão de um envio (regras C1 a C6 de docs/training/mvp-m3-envio-de-demos.md), com a linha
 * travada: de duas conclusões simultâneas, uma conclui e a outra encontra o envio fechado.
 *
 * Uma recusa do arquivo (tamanho, assinatura, repetido) é gravada **antes** de responder, para o
 * envio deixar de ser o aberto do usuário, e o arquivo é apagado. Na M4, a conclusão passa a
 * enfileirar a análise.
 *
 * Falha do disco ao medir, ler, calcular o hash ou renomear (#54) não é recusa: a transação desfaz o
 * que foi gravado no banco, o arquivo fica onde estava e a resposta é 503, para nova tentativa. A
 * promoção (`.part` → `.dem`) acontece antes do commit; se o commit não chegar a acontecer, a próxima
 * tentativa recupera o `.dem` (`reclaimPromoted`).
 */
class CompleteMatchUpload
{
    public function __construct(private readonly UploadStorage $storage) {}

    /** @throws MatchUploadFailed */
    public function handle(MatchUpload $upload): MatchUpload
    {
        try {
            /** @var array{0: MatchUpload, 1: MatchUploadFailed|null} $outcome */
            $outcome = DB::transaction(fn (): array => $this->conclude($upload));
        } catch (UniqueConstraintViolationException) {
            // Mesmo arquivo concluído por outro caminho ao mesmo tempo (C6).
            $outcome = $this->close($upload, 'duplicate', MatchUploadFailed::duplicate());
        } catch (UploadStorageFailed $exception) {
            Log::warning('matches.uploads.storage-failed', ['operation' => $exception->operation, 'upload' => $exception->uploadId]);

            throw MatchUploadFailed::storageUnavailable((int) config('matches.storage_retry_after_seconds'));
        }

        [$result, $failure] = $outcome;
        if ($failure !== null) {
            $this->storage->delete($result->id);
            throw $failure;
        }

        ExpireMatchUpload::scheduleFor($result);

        return $result;
    }

    /** @return array{0: MatchUpload, 1: MatchUploadFailed|null} */
    private function conclude(MatchUpload $upload): array
    {
        $locked = MatchUpload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

        // C1 e C2.
        if ($locked->status !== MatchUploadStatus::Uploading || $locked->expires_at->isPast()) {
            throw MatchUploadFailed::notOpen();
        }
        if ($locked->parts_received !== $locked->parts_total) {
            throw MatchUploadFailed::incomplete();
        }

        // C3: o vínculo pode ter sido desfeito durante o envio.
        if (! $locked->user()->firstOrFail()->steamAccount()->exists()) {
            throw MatchUploadFailed::steamLinkRequired();
        }

        // Uma tentativa anterior renomeou o arquivo, mas não gravou a conclusão.
        $this->storage->reclaimPromoted($locked->id);

        // C4 e C5: o arquivo montado tem o tamanho declarado e é uma demo do Source 2.
        if ($this->storage->partSize($locked->id) !== $locked->size_bytes) {
            return $this->reject($locked, 'size_mismatch', MatchUploadFailed::sizeMismatch());
        }
        if (! DemoSignature::matches($this->storage->head($locked->id, strlen(DemoSignature::MAGIC)))) {
            return $this->reject($locked, 'not_a_demo', MatchUploadFailed::notADemo());
        }

        // C6: o mesmo arquivo do mesmo usuário entre os concluídos (U5).
        $sha256 = $this->storage->sha256($locked->id);
        $duplicate = MatchUpload::query()
            ->where('user_id', $locked->user_id)
            ->where('status', MatchUploadStatus::Completed)
            ->where('sha256', $sha256)
            ->exists();
        if ($duplicate) {
            return $this->reject($locked, 'duplicate', MatchUploadFailed::duplicate());
        }

        $now = Carbon::now();
        $locked->forceFill([
            'status' => MatchUploadStatus::Completed,
            'sha256' => $sha256,
            'completed_at' => $now,
            'expires_at' => $now->copy()->addHours((int) config('matches.completed_ttl_hours')),
        ])->save();
        $this->storage->promote($locked->id);

        return [$locked, null];
    }

    /** @return array{0: MatchUpload, 1: MatchUploadFailed} */
    private function reject(MatchUpload $locked, string $code, MatchUploadFailed $failure): array
    {
        $locked->forceFill(['status' => MatchUploadStatus::Rejected, 'rejection_code' => $code])->save();

        return [$locked, $failure];
    }

    /** @return array{0: MatchUpload, 1: MatchUploadFailed} */
    private function close(MatchUpload $upload, string $code, MatchUploadFailed $failure): array
    {
        return DB::transaction(function () use ($upload, $code, $failure): array {
            $locked = MatchUpload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();

            return $this->reject($locked, $code, $failure);
        });
    }
}
