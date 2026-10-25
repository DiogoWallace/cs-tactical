<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Steam\SteamLinkFailed;
use App\Modules\Identity\Steam\SteamOpenId;
use Illuminate\Contracts\Session\Session;

/**
 * Início do vínculo com a Steam (etapa M2): um `state` novo, de 32 bytes, guardado na sessão por
 * 10 minutos, e a URL de login da Steam que volta para a página do frontend com esse `state`.
 * Um início novo substitui o anterior.
 */
class StartSteamLink
{
    public const SESSION_KEY = 'steam_link';

    public function __construct(private readonly SteamOpenId $openId) {}

    /** @throws SteamLinkFailed */
    public function handle(User $user, Session $session): string
    {
        if ($user->steamAccount()->exists()) {
            throw SteamLinkFailed::alreadyLinked();
        }

        $state = bin2hex(random_bytes(32));
        $session->put(self::SESSION_KEY, [
            'state' => $state,
            'expires_at' => now()->addSeconds((int) config('steam.state_ttl'))->getTimestamp(),
        ]);

        return $this->openId->loginUrl($state);
    }
}
