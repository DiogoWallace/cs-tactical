<?php

namespace App\Modules\Identity\Steam;

use Illuminate\Support\Carbon;

/**
 * OpenID 2.0 com o perfil fixo da Steam, sem rede: monta a URL de login e confere a resposta
 * (regras 3 a 8 de docs/training/mvp-m2-vinculo-steam.md, exceto a unicidade do nonce, que fica
 * com quem guarda estado). A verificação com a Steam é do SteamOpenIdVerifier.
 *
 * As respostas chegam como `campo => valor`, sem o prefixo `openid.` (o frontend as agrupa num
 * objeto, porque o Laravel lê o ponto como aninhamento); `params()` remonta os nomes originais.
 */
final class SteamOpenId
{
    public const NS = 'http://specs.openid.net/auth/2.0';

    private const IDENTIFIER_SELECT = 'http://specs.openid.net/auth/2.0/identifier_select';

    private const CLAIMED_ID = '#^https?://steamcommunity\.com/openid/id/(7656119\d{10})$#';

    /** Campos que a assinatura precisa cobrir (regra 7). */
    private const REQUIRED_SIGNED = ['op_endpoint', 'claimed_id', 'identity', 'return_to', 'response_nonce', 'assoc_handle'];

    /** Campos `openid.*` aceitos na resposta; qualquer outro é recusado pelo Form Request. */
    public const RESPONSE_FIELDS = ['ns', 'mode', 'op_endpoint', 'claimed_id', 'identity', 'return_to', 'response_nonce', 'assoc_handle', 'signed', 'sig'];

    public function endpoint(): string
    {
        return (string) config('steam.openid_endpoint');
    }

    /** Página de volta, sempre a partir da configuração (nunca do Host da requisição). */
    public function returnTo(string $state): string
    {
        return config('app.frontend_url').config('steam.return_path').'?'.http_build_query(['state' => $state]);
    }

    public function loginUrl(string $state): string
    {
        return $this->endpoint().'?'.http_build_query([
            'openid.ns' => self::NS,
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $this->returnTo($state),
            'openid.realm' => config('app.frontend_url'),
            'openid.identity' => self::IDENTIFIER_SELECT,
            'openid.claimed_id' => self::IDENTIFIER_SELECT,
        ]);
    }

    /**
     * Confere a resposta e devolve o SteamID64. Cada falha encerra com um código estável.
     *
     * @param  array<string, string>  $response  campos sem o prefixo `openid.`
     *
     * @throws SteamLinkFailed
     */
    public function validate(array $response, string $state): string
    {
        // Regra 3: o jogador desistiu na Steam.
        if (($response['mode'] ?? null) === 'cancel') {
            throw SteamLinkFailed::cancelled();
        }
        if (($response['mode'] ?? null) !== 'id_res') {
            throw SteamLinkFailed::responseInvalid();
        }

        // Regra 4: OpenID 2.0, do endpoint configurado.
        if (($response['ns'] ?? null) !== self::NS || ($response['op_endpoint'] ?? null) !== $this->endpoint()) {
            throw SteamLinkFailed::responseInvalid();
        }

        // Regra 5: a volta é exatamente a URL montada com o state desta sessão.
        if (! hash_equals($this->returnTo($state), (string) ($response['return_to'] ?? ''))) {
            throw SteamLinkFailed::responseInvalid();
        }

        // Regra 6: identidade de um SteamID64, igual nos dois campos.
        $claimed = (string) ($response['claimed_id'] ?? '');
        if ($claimed !== ($response['identity'] ?? null) || preg_match(self::CLAIMED_ID, $claimed, $match) !== 1) {
            throw SteamLinkFailed::responseInvalid();
        }

        // Regra 7: a assinatura cobre o que importa.
        $signed = explode(',', (string) ($response['signed'] ?? ''));
        if (array_diff(self::REQUIRED_SIGNED, $signed) !== [] || ($response['sig'] ?? '') === '') {
            throw SteamLinkFailed::responseInvalid();
        }

        // Regra 8 (data): o nonce começa com a hora UTC em que a Steam respondeu.
        if (! $this->nonceIsFresh((string) ($response['response_nonce'] ?? ''))) {
            throw SteamLinkFailed::replay();
        }

        return $match[1];
    }

    /**
     * Os campos com o prefixo `openid.` de volta, como a Steam os mandou.
     *
     * @param  array<string, string>  $response
     * @return array<string, string>
     */
    public function params(array $response): array
    {
        $params = [];
        foreach ($response as $field => $value) {
            $params["openid.{$field}"] = $value;
        }

        return $params;
    }

    private function nonceIsFresh(string $nonce): bool
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z)./', $nonce, $match) !== 1) {
            return false;
        }

        $issued = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $match[1], 'UTC');

        return $issued !== null && abs(Carbon::now()->diffInSeconds($issued)) <= (int) config('steam.nonce_skew');
    }
}
