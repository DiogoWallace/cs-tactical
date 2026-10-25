<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;

/**
 * Desfaz o vínculo com a Steam (etapa M2): apaga a linha, sem histórico. A senha atual é conferida
 * antes, pelo Form Request (decisão S2). Sem vínculo, não faz nada.
 */
class UnlinkSteamAccount
{
    public function handle(User $user): void
    {
        $user->steamAccount()->delete();
    }
}
