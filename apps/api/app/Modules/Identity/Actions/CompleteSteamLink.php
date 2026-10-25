<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserSteamAccount;
use App\Modules\Identity\Steam\SteamLinkFailed;
use App\Modules\Identity\Steam\SteamOpenId;
use App\Modules\Identity\Steam\SteamOpenIdVerifier;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Conclusão do vínculo com a Steam (etapa M2), na ordem das regras de
 * docs/training/mvp-m2-vinculo-steam.md; qualquer falha encerra sem gravar nada:
 *
 * 2. `state` igual ao da sessão e dentro do prazo, consumido na primeira tentativa (mesmo se falhar);
 * 3–8. resposta conferida pelo SteamOpenId; nonce nunca visto (Redis, 10 minutos);
 * 9. confirmação da própria Steam (`check_authentication`);
 * 10. gravação em transação: os únicos do banco decidem "já vinculado" e "SteamID de outra conta".
 */
class CompleteSteamLink
{
    public function __construct(
        private readonly SteamOpenId $openId,
        private readonly SteamOpenIdVerifier $verifier,
    ) {}

    /**
     * @param  array<string, string>  $response  campos `openid.*` sem o prefixo
     *
     * @throws SteamLinkFailed
     */
    public function handle(User $user, Session $session, ?string $state, array $response): UserSteamAccount
    {
        /** @var array{state?: string, expires_at?: int}|null $pending */
        $pending = $session->pull(StartSteamLink::SESSION_KEY);
        if (
            ! is_string($state) || ! is_array($pending)
            || ! hash_equals((string) ($pending['state'] ?? ''), $state)
            || (int) ($pending['expires_at'] ?? 0) < now()->getTimestamp()
        ) {
            throw SteamLinkFailed::stateInvalid();
        }

        $steamId = $this->openId->validate($response, $state);

        // Regra 8 (unicidade): a mesma resposta não serve duas vezes, nem em outra sessão.
        $nonceKey = 'steam-openid-nonce:'.hash('sha256', $response['response_nonce']);
        if (! Cache::add($nonceKey, true, (int) config('steam.state_ttl'))) {
            throw SteamLinkFailed::replay();
        }

        $this->verifier->verify($this->openId->params($response));

        return $this->link($user, $steamId);
    }

    /**
     * Grava o vínculo. Público para o teste de concorrência (dois usuários, o mesmo SteamID).
     *
     * @throws SteamLinkFailed
     */
    public function link(User $user, string $steamId): UserSteamAccount
    {
        try {
            return DB::transaction(function () use ($user, $steamId): UserSteamAccount {
                if (UserSteamAccount::query()->where('user_id', $user->getKey())->exists()) {
                    throw SteamLinkFailed::alreadyLinked();
                }

                $account = new UserSteamAccount;
                $account->forceFill([
                    'user_id' => $user->getKey(),
                    'steam_id' => $steamId,
                    'linked_at' => now(),
                ])->save();

                return $account;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Mesma resposta para qualquer dono do SteamID: não revela qual conta o usa.
            throw str_contains($e->getMessage(), 'user_steam_accounts_user_id_unique')
                ? SteamLinkFailed::alreadyLinked()
                : SteamLinkFailed::accountInUse();
        }
    }
}
