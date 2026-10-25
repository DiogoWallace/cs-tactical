<?php

namespace App\Modules\Matches\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Exceptions\MatchUploadFailed;
use App\Modules\Matches\Exceptions\UploadStorageFailed;
use App\Modules\Matches\Jobs\ExpireMatchUpload;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\UploadReservations;
use App\Modules\Matches\Uploads\UploadStorage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Início de um envio (regras I2 a I6 de docs/training/mvp-m3-envio-de-demos.md; I1 e I3 ficam com
 * a rota e o Form Request). O tamanho das partes é da configuração, nunca do cliente.
 */
class StartMatchUpload
{
    public function __construct(
        private readonly UploadStorage $storage,
        private readonly UploadReservations $reservations,
    ) {}

    /** @throws MatchUploadFailed */
    public function handle(User $user, string $name, int $size): MatchUpload
    {
        // I2: sem vínculo Steam não há envio (ADR 0022, item 2).
        if (! $user->steamAccount()->exists()) {
            throw MatchUploadFailed::steamLinkRequired();
        }

        // I4: um envio aberto por usuário (o único parcial do banco decide a corrida, abaixo).
        if (MatchUpload::query()->where('user_id', $user->getKey())->where('status', MatchUploadStatus::Uploading)->exists()) {
            throw MatchUploadFailed::alreadyOpen();
        }

        // I5: cota de envios concluídos nas últimas 24 horas.
        $since = Carbon::now()->subDay();
        $recent = MatchUpload::query()
            ->where('user_id', $user->getKey())
            ->where('completed_at', '>=', $since)
            ->orderBy('completed_at')
            ->pluck('completed_at');
        if ($recent->count() >= (int) config('matches.daily_completed_limit')) {
            /** @var Carbon $oldest */
            $oldest = $recent->first();
            // Para cima: o cliente não deve tentar antes de o envio mais antigo sair da janela.
            throw MatchUploadFailed::quotaExceeded((int) ceil(Carbon::now()->diffInSeconds($oldest->copy()->addDay())));
        }

        // I6 (#54, D1): o arquivo, o que os envios abertos ainda vão gravar e a reserva precisam
        // caber no espaço livre. O lock de aviso serializa os inícios de todos os usuários, para que
        // dois não contem o mesmo espaço; o único parcial continua decidindo o mesmo usuário (I4).
        $upload = DB::transaction(function () use ($user, $name, $size): MatchUpload {
            $this->reservations->lock();
            try {
                $free = $this->storage->freeBytes();
            } catch (UploadStorageFailed $exception) {
                Log::warning('matches.uploads.storage-failed', ['operation' => $exception->operation, 'upload' => null]);

                throw MatchUploadFailed::storageUnavailable();
            }
            if ($free - $this->reservations->pendingBytes() < $size + (int) config('matches.disk_reserve_bytes')) {
                throw MatchUploadFailed::storageUnavailable();
            }

            $partSize = (int) config('matches.part_size_bytes');
            $now = Carbon::now();
            $upload = new MatchUpload;
            $upload->forceFill([
                'user_id' => $user->getKey(),
                'status' => MatchUploadStatus::Uploading,
                'name' => $name,
                'size_bytes' => $size,
                'part_size' => $partSize,
                'parts_total' => (int) ceil($size / $partSize),
                'parts_received' => 0,
                'last_activity_at' => $now,
                'expires_at' => $now->copy()->addMinutes((int) config('matches.idle_minutes')),
            ]);

            try {
                $upload->save();
            } catch (UniqueConstraintViolationException) {
                throw MatchUploadFailed::alreadyOpen();
            }

            return $upload;
        });

        ExpireMatchUpload::scheduleFor($upload);

        return $upload;
    }
}
