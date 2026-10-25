<?php

namespace App\Modules\Matches\Jobs;

use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\UploadStorage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Expira um envio vencido e apaga o arquivo (etapa M3, decisão U2: fila existente, sem agendador).
 *
 * Um job atrasado é enfileirado no início, a cada parte e na conclusão, para o `expires_at` do
 * momento. Ele não se reagenda: só age se o envio ainda estiver aberto ou concluído e o prazo
 * gravado já tiver passado; um job antigo, de antes de uma parte nova, não faz nada. O
 * `matches:prune-uploads` cobre o que uma fila perdida deixar para trás.
 */
class ExpireMatchUpload implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $uploadId) {}

    public static function scheduleFor(MatchUpload $upload): void
    {
        self::dispatch($upload->id)->delay($upload->expires_at);
    }

    public function handle(UploadStorage $storage): void
    {
        $expired = DB::transaction(function (): bool {
            $upload = MatchUpload::query()->whereKey($this->uploadId)->lockForUpdate()->first();
            if ($upload === null || ! self::isOverdue($upload)) {
                return false;
            }

            $upload->forceFill(['status' => MatchUploadStatus::Expired])->save();

            return true;
        });

        if ($expired) {
            $storage->delete($this->uploadId);
        }
    }

    /** Aberto ou concluído, com o prazo gravado vencido. */
    public static function isOverdue(MatchUpload $upload): bool
    {
        return in_array($upload->status, [MatchUploadStatus::Uploading, MatchUploadStatus::Completed], true)
            && $upload->expires_at->isPast();
    }
}
