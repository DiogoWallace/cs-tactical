<?php

namespace App\Modules\Identity\Steam;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Regra 9 de docs/training/mvp-m2-vinculo-steam.md: pergunta à Steam se a resposta é dela
 * (`check_authentication`). Único ponto com rede do vínculo; nos testes, `Http::fake()`.
 *
 * Vai só ao endpoint da configuração (oficial em produção), sem seguir redirecionamentos e com
 * tempo curto: nenhum destino vem do cliente.
 */
class SteamOpenIdVerifier
{
    public function __construct(private readonly SteamOpenId $openId) {}

    /**
     * @param  array<string, string>  $params  os `openid.*` recebidos, sem alteração
     *
     * @throws SteamLinkFailed
     */
    public function verify(array $params): void
    {
        $timeout = (int) config('steam.timeout');

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout($timeout)
                ->withoutRedirecting()
                ->post($this->openId->endpoint(), [...$params, 'openid.mode' => 'check_authentication']);
        } catch (ConnectionException) {
            throw SteamLinkFailed::unavailable();
        }

        if ($response->status() !== 200) {
            throw SteamLinkFailed::unavailable();
        }

        // Corpo em "chave:valor" por linha (OpenID 2.0, seção 11.4.2.2).
        $fields = [];
        foreach (preg_split('/\r?\n/', $response->body()) ?: [] as $line) {
            [$key, $value] = array_pad(explode(':', $line, 2), 2, '');
            $fields[trim($key)] = trim($value);
        }

        if (($fields['ns'] ?? null) !== SteamOpenId::NS || ($fields['is_valid'] ?? null) !== 'true') {
            throw SteamLinkFailed::notVerified();
        }
    }
}
