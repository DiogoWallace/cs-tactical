<?php

/*
| IP real do cliente atrás do proxy do Next.js (ADR 0009).
| Só o container web é confiável; X-Forwarded-* de qualquer outro remetente é ignorado.
*/

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

const NEXT_PROXY_IP = '172.31.250.10';
const DOCKER_GATEWAY_IP = '172.31.250.1';
const CLIENT_IP = '198.51.100.7';
const SPOOFED_IP = '203.0.113.66';

beforeEach(function () {
    config(['trustedproxy.proxies' => [NEXT_PROXY_IP]]);

    RateLimiter::for('ip-probe', fn (Request $request) => Limit::perMinute(1)->by($request->ip()));

    Route::middleware('api')->get('/api/v1/__ip-probe', fn (Request $request) => [
        'ip' => $request->ip(),
        'host' => $request->getHost(),
        'secure' => $request->isSecure(),
    ]);
    Route::middleware(['api', 'throttle:ip-probe'])->get('/api/v1/__throttled-probe', fn () => ['ok' => true]);
});

function fromAddress(string $remoteAddr, array $headers = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->withHeaders($headers)
        ->getJson('/api/v1/__ip-probe');
}

it('uses the connection address when the request does not come from a trusted proxy', function () {
    fromAddress(CLIENT_IP)->assertJsonPath('ip', CLIENT_IP);
});

it('ignores a spoofed X-Forwarded-For sent directly to the API', function () {
    fromAddress(CLIENT_IP, ['X-Forwarded-For' => SPOOFED_IP])->assertJsonPath('ip', CLIENT_IP);
});

it('does not trust the docker gateway, even though it is on the internal network', function () {
    // Requisições do host para a porta 8000 publicada chegam com o IP do gateway.
    fromAddress(DOCKER_GATEWAY_IP, ['X-Forwarded-For' => SPOOFED_IP])
        ->assertJsonPath('ip', DOCKER_GATEWAY_IP);
});

it('uses the forwarded address when the request comes from the Next.js proxy', function () {
    fromAddress(NEXT_PROXY_IP, ['X-Forwarded-For' => CLIENT_IP])->assertJsonPath('ip', CLIENT_IP);
});

it('falls back to the proxy address when the proxy forwards no client address', function () {
    fromAddress(NEXT_PROXY_IP)->assertJsonPath('ip', NEXT_PROXY_IP);
});

it('uses the rightmost address, ignoring entries prepended by the client', function () {
    // O cliente envia "X-Forwarded-For: <falso>" e o proxy de borda acrescenta o IP que ele viu.
    fromAddress(NEXT_PROXY_IP, ['X-Forwarded-For' => SPOOFED_IP.', '.CLIENT_IP])
        ->assertJsonPath('ip', CLIENT_IP);
});

it('skips trusted proxies at the end of the chain', function () {
    fromAddress(NEXT_PROXY_IP, ['X-Forwarded-For' => CLIENT_IP.', '.NEXT_PROXY_IP])
        ->assertJsonPath('ip', CLIENT_IP);
});

it('never reports a malformed forwarded value as the client address', function () {
    $ip = fromAddress(NEXT_PROXY_IP, ['X-Forwarded-For' => 'not-an-ip'])->json('ip');

    expect($ip)->not->toBe('not-an-ip')
        ->and(filter_var($ip, FILTER_VALIDATE_IP))->not->toBeFalse();
});

it('never trusts X-Forwarded-Host, even from the proxy', function () {
    fromAddress(NEXT_PROXY_IP, ['X-Forwarded-Host' => 'evil.example'])
        ->assertJsonPath('host', 'localhost');
});

it('ignores the RFC 7239 Forwarded header, even from the proxy', function () {
    fromAddress(NEXT_PROXY_IP, ['Forwarded' => 'for='.SPOOFED_IP])->assertJsonPath('ip', NEXT_PROXY_IP);
    fromAddress(NEXT_PROXY_IP, ['Forwarded' => 'for='.SPOOFED_IP, 'X-Forwarded-For' => CLIENT_IP])
        ->assertJsonPath('ip', CLIENT_IP);
});

it('ignores other client IP headers, even from the proxy', function () {
    fromAddress(NEXT_PROXY_IP, [
        'X-Real-IP' => SPOOFED_IP,
        'Client-IP' => SPOOFED_IP,
        'True-Client-IP' => SPOOFED_IP,
        'CF-Connecting-IP' => SPOOFED_IP,
    ])->assertJsonPath('ip', NEXT_PROXY_IP);
});

describe('production topologies (ADR 0009)', function () {
    $edge = '10.20.0.5';

    it('trusts an edge proxy that routes /api straight to the API', function () use ($edge) {
        config(['trustedproxy.proxies' => [$edge]]);

        // A borda acrescenta o IP da conexão ao que o cliente enviou.
        fromAddress($edge, ['X-Forwarded-For' => SPOOFED_IP.', '.CLIENT_IP])
            ->assertJsonPath('ip', CLIENT_IP);
    });

    it('keeps the client address if a hop between edge and API also appends its own', function () use ($edge) {
        config(['trustedproxy.proxies' => [$edge, NEXT_PROXY_IP]]);

        fromAddress(NEXT_PROXY_IP, ['X-Forwarded-For' => SPOOFED_IP.', '.CLIENT_IP.', '.$edge])
            ->assertJsonPath('ip', CLIENT_IP);
    });

    it('degrades to an untrusted hop, never to a client-chosen value', function () use ($edge) {
        // Ex.: uma versão futura do Next passa a acrescentar o IP da borda, que não está na lista.
        fromAddress(NEXT_PROXY_IP, ['X-Forwarded-For' => SPOOFED_IP.', '.CLIENT_IP.', '.$edge])
            ->assertJsonPath('ip', $edge);
    });
});

it('trusts X-Forwarded-Proto from the proxy', function () {
    fromAddress(NEXT_PROXY_IP, ['X-Forwarded-Proto' => 'https'])->assertJsonPath('secure', true);
});

it('ignores X-Forwarded-Proto from any other sender', function () {
    fromAddress(CLIENT_IP, ['X-Forwarded-Proto' => 'https'])->assertJsonPath('secure', false);
});

it('trusts nobody when no proxy is configured', function () {
    config(['trustedproxy.proxies' => null]);

    fromAddress(NEXT_PROXY_IP, ['X-Forwarded-For' => SPOOFED_IP])->assertJsonPath('ip', NEXT_PROXY_IP);
});

it('rate limits by the real client address, so rotating a spoofed header does not help', function () {
    $direct = fn (string $forwarded) => test()->withServerVariables(['REMOTE_ADDR' => CLIENT_IP])
        ->withHeader('X-Forwarded-For', $forwarded)
        ->getJson('/api/v1/__throttled-probe');

    $direct('203.0.113.1')->assertOk();
    $direct('203.0.113.2')->assertStatus(429);
});

it('gives each client behind the proxy its own rate limit bucket', function () {
    $viaProxy = fn (string $client) => test()->withServerVariables(['REMOTE_ADDR' => NEXT_PROXY_IP])
        ->withHeader('X-Forwarded-For', $client)
        ->getJson('/api/v1/__throttled-probe');

    $viaProxy('198.51.100.1')->assertOk();
    $viaProxy('198.51.100.2')->assertOk();
    $viaProxy('198.51.100.1')->assertStatus(429);
});

describe('config/trustedproxy.php', function () {
    $load = function (string $value): mixed {
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
        putenv("TRUSTED_PROXIES={$value}");

        try {
            return (require config_path('trustedproxy.php'))['proxies'];
        } finally {
            unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
            putenv('TRUSTED_PROXIES');
        }
    };

    it('parses a comma separated list', fn () => expect($load(' 172.31.250.10 , 10.0.0.0/8 '))
        ->toBe(['172.31.250.10', '10.0.0.0/8']));

    it('treats an empty value as no trusted proxy', fn () => expect($load(''))->toBeNull());

    it('refuses wildcards that would trust any sender', fn () => expect($load('*, **, REMOTE_ADDR'))
        ->toBeNull());
});
