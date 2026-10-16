<?php

/*
| Valida a base da autenticação por sessão (ADR 0003) antes do login existir.
| Os fluxos completos (login, logout, /me) são testados na Fase 1.
*/

use Illuminate\Support\Facades\Route;

const FRONTEND_ORIGIN = 'http://localhost:3000';

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost:3000']]);

    // Rota descartável, só existe durante o teste, para exercitar o middleware da API.
    Route::middleware('api')->post('/api/v1/__csrf-probe', fn () => response()->json(['ok' => true]));
});

it('issues the XSRF-TOKEN and an http-only session cookie', function () {
    $response = $this->withHeader('Origin', FRONTEND_ORIGIN)->get('/sanctum/csrf-cookie');

    $response->assertNoContent();

    $xsrf = $response->getCookie('XSRF-TOKEN', decrypt: false);
    $session = $response->getCookie(config('session.cookie'), decrypt: false);

    expect($xsrf)->not->toBeNull()
        ->and($xsrf->isHttpOnly())->toBeFalse()
        ->and($xsrf->getSameSite())->toBe('lax')
        ->and($session)->not->toBeNull()
        ->and($session->isHttpOnly())->toBeTrue()
        ->and($session->getSameSite())->toBe('lax');
});

/**
 * O Laravel desliga a verificação CSRF quando APP_ENV=testing. Para validar o
 * comportamento real, estes testes rodam como "local" e repetem o fluxo do navegador:
 * buscar o cookie CSRF e devolver os cookies + header X-XSRF-TOKEN.
 */
function withRealCsrfVerification(): void
{
    app()->instance('env', 'local');
    // Sessões no Redis (como em dev/produção), para sobreviverem entre requisições do teste.
    config(['session.driver' => 'redis']);
}

/** Simula uma nova requisição do navegador: o estado da sessão só vem do cookie + Redis. */
function forgetInMemorySession(): void
{
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');
}

it('rejects state-changing requests from the frontend without a CSRF token', function () {
    withRealCsrfVerification();

    $this->withHeader('Origin', FRONTEND_ORIGIN)
        ->postJson('/api/v1/__csrf-probe')
        ->assertStatus(419);
});

it('accepts state-changing requests that send back the XSRF-TOKEN cookie as a header', function () {
    withRealCsrfVerification();

    $csrf = $this->withHeader('Origin', FRONTEND_ORIGIN)->get('/sanctum/csrf-cookie');
    $xsrf = $csrf->getCookie('XSRF-TOKEN', decrypt: false)->getValue();
    $session = $csrf->getCookie(config('session.cookie'), decrypt: false)->getValue();
    forgetInMemorySession();

    $this->withCredentials()
        ->withUnencryptedCookies([config('session.cookie') => $session, 'XSRF-TOKEN' => $xsrf])
        ->withHeaders(['Origin' => FRONTEND_ORIGIN, 'X-XSRF-TOKEN' => $xsrf])
        ->postJson('/api/v1/__csrf-probe')
        ->assertOk();
});

it('rejects a CSRF token that does not belong to the session', function () {
    withRealCsrfVerification();

    $first = $this->withHeader('Origin', FRONTEND_ORIGIN)->get('/sanctum/csrf-cookie');
    forgetInMemorySession();
    $second = $this->withHeader('Origin', FRONTEND_ORIGIN)->get('/sanctum/csrf-cookie');
    forgetInMemorySession();

    $this->withCredentials()->withUnencryptedCookies([
        config('session.cookie') => $first->getCookie(config('session.cookie'), decrypt: false)->getValue(),
    ])
        ->withHeaders([
            'Origin' => FRONTEND_ORIGIN,
            'X-XSRF-TOKEN' => $second->getCookie('XSRF-TOKEN', decrypt: false)->getValue(),
        ])
        ->postJson('/api/v1/__csrf-probe')
        ->assertStatus(419);
});

it('does not start a session for origins outside the stateful list', function () {
    $response = $this->withHeader('Origin', 'https://evil.example')
        ->postJson('/api/v1/__csrf-probe');

    $response->assertOk();
    expect($response->getCookie(config('session.cookie'), decrypt: false))->toBeNull();
});

it('does not allow cross-origin requests via CORS', function () {
    $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])
        ->options('/api/v1/health')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});
