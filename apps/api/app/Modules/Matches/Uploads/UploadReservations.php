<?php

namespace App\Modules\Matches\Uploads;

use App\Modules\Matches\Enums\MatchUploadStatus;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Espaço que os envios abertos ainda vão gravar (regra I6, decisão D1 da #54).
 *
 * O espaço livre do disco só mostra o que já foi gravado; sem descontar o que falta chegar, dois
 * jogadores começando ao mesmo tempo contariam o mesmo espaço. Os inícios passam por um lock de
 * aviso do PostgreSQL, na mesma transação que lê a reserva e cria o envio.
 */
class UploadReservations
{
    /** Chave fixa do `pg_advisory_xact_lock` dos inícios de envio (qualquer usuário). */
    public const LOCK_KEY = 2026_10_25_54;

    /** Serializa os inícios até o fim da transação atual. */
    public function lock(): void
    {
        DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
    }

    /**
     * Bytes que faltam chegar nos envios abertos e ainda dentro do prazo. Por envio:
     * `max(0, size_bytes − min(size_bytes, parts_received × part_size))` (a última parte é menor que
     * `part_size`, então o produto pode passar do tamanho). Envio vencido não recebe mais partes e o
     * que já gravou está no espaço usado do disco; concluídos também.
     */
    public function pendingBytes(): int
    {
        return (int) DB::table('match_uploads')
            ->where('status', MatchUploadStatus::Uploading->value)
            ->where('expires_at', '>', Carbon::now())
            ->sum(DB::raw('greatest(0, size_bytes - least(size_bytes, parts_received::bigint * part_size))'));
    }
}
