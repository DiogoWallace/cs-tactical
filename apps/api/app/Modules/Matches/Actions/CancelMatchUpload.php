<?php

namespace App\Modules\Matches\Actions;

use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Exceptions\MatchUploadFailed;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\UploadStorage;
use Illuminate\Support\Facades\DB;

/** Cancela um envio aberto ou concluído (ainda sem análise) e apaga o arquivo (etapa M3). */
class CancelMatchUpload
{
    public function __construct(private readonly UploadStorage $storage) {}

    /** @throws MatchUploadFailed */
    public function handle(MatchUpload $upload): void
    {
        DB::transaction(function () use ($upload): void {
            $locked = MatchUpload::query()->whereKey($upload->getKey())->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [MatchUploadStatus::Uploading, MatchUploadStatus::Completed], true)) {
                throw MatchUploadFailed::notOpen();
            }

            $locked->forceFill(['status' => MatchUploadStatus::Cancelled])->save();
        });

        $this->storage->delete($upload->id);
    }
}
