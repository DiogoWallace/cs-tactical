<?php

namespace App\Modules\Matches\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Matches\Enums\MatchUploadStatus;
use App\Modules\Matches\Exceptions\MatchUploadFailed;
use App\Modules\Matches\Jobs\ExpireMatchUpload;
use App\Modules\Matches\Models\MatchUpload;
use App\Modules\Matches\Uploads\UploadStorage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

/**
 * Início de um envio (regras I2 a I6 de docs/training/mvp-m3-envio-de-demos.md; I1 e I3 ficam com
 * a rota e o Form Request). O tamanho das partes é da configuração, nunca do cliente.
 */
class StartMatchUpload
{
    public function __construct(private readonly UploadStorage $storage) {}

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

        // I6: espaço para o arquivo e uma reserva.
        if ($this->storage->freeBytes() < $size + (int) config('matches.disk_reserve_bytes')) {
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

        ExpireMatchUpload::scheduleFor($upload);

        return $upload;
    }
}
