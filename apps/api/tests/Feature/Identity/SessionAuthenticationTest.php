<?php

use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrontendSession;

/*
| Login, logout e /me com sessão real no Redis e verificação CSRF real (APP_ENV=local),
| reproduzindo o navegador no frontend (ADR 0003, docs/architecture/autenticacao.md).
*/

function browser(): FrontendSession
{
    return (new FrontendSession(test()))->realCsrf();
}

function loginAs(FrontendSession $session, string $email = 'player@example.com', string $password = 'secret-password-1'): TestResponse
{
    if ($session->cookie('XSRF-TOKEN') === null) {
        $session->csrfCookie()->assertNoContent();
    }

    return $session->post('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

beforeEach(function () {
    $this->user = User::factory()->withProfile()->create([
        'email' => 'player@example.com',
        'password' => 'secret-password-1',
    ]);
});

describe('login', function () {
    it('authenticates and returns the user', function () {
        $session = browser();

        loginAs($session)
            ->assertOk()
            ->assertJsonPath('data.id', $this->user->id)
            ->assertJsonPath('data.profile.nickname', $this->user->profile->nickname);

        $session->get('/api/v1/me')->assertOk()->assertJsonPath('data.email', 'player@example.com');
    });

    it('accepts the e-mail in any case', function () {
        loginAs(browser(), ' PLAYER@Example.com ')->assertOk();
    });

    it('regenerates the session id (session fixation)', function () {
        $session = browser();
        $session->csrfCookie();
        $before = $session->cookie('cs_tactical_session');

        loginAs($session)->assertOk();

        expect($session->cookie('cs_tactical_session'))->not->toBeNull()->not->toBe($before)
            ->and(decrypt($session->cookie('cs_tactical_session'), false))
            ->not->toBe(decrypt($before, false));
    });

    it('gives the same answer for a wrong password and an unknown e-mail', function () {
        $wrongPassword = loginAs(browser(), password: 'wrong-password')->assertUnprocessable();
        $unknownEmail = loginAs(browser(), 'nobody@example.com')->assertUnprocessable();

        expect($wrongPassword->json())->toBe($unknownEmail->json())
            ->and($wrongPassword->json('code'))->toBe('validation_failed')
            ->and(array_keys($wrongPassword->json('errors')))->toBe(['email']);
    });

    it('does not authenticate after a failed attempt', function () {
        $session = browser();
        loginAs($session, password: 'wrong-password')->assertUnprocessable();

        $session->get('/api/v1/me')->assertUnauthorized();
    });

    it('requires the CSRF token', function () {
        $session = browser();
        $session->csrfCookie();

        $session->withoutXsrfHeader()
            ->post('/api/v1/auth/login', ['email' => 'player@example.com', 'password' => 'secret-password-1'])
            ->assertStatus(419)
            ->assertJsonPath('code', 'csrf_token_mismatch');

        $session->get('/api/v1/me')->assertUnauthorized();
    });

    it('rejects a CSRF token from another session', function () {
        $attacker = browser();
        $attacker->csrfCookie();

        $victim = browser();
        $victim->csrfCookie();

        FrontendSession::forgetRequestState();
        $this->withCredentials()
            ->withUnencryptedCookies(['cs_tactical_session' => $victim->cookie('cs_tactical_session')])
            ->withHeaders(['Origin' => FrontendSession::ORIGIN, 'X-XSRF-TOKEN' => $attacker->cookie('XSRF-TOKEN')])
            ->postJson('/api/v1/auth/login', ['email' => 'player@example.com', 'password' => 'secret-password-1'])
            ->assertStatus(419);
    });

    it('rejects login while already authenticated', function () {
        $session = browser();
        loginAs($session)->assertOk();

        loginAs($session)->assertStatus(409)->assertJsonPath('code', 'already_authenticated');
    });

    it('only accepts requests from the frontend', function () {
        $this->postJson('/api/v1/auth/login', ['email' => 'player@example.com', 'password' => 'secret-password-1'])
            ->assertForbidden()
            ->assertJsonPath('code', 'frontend_session_required');
    });

    it('is limited to 5 attempts per minute per e-mail and IP', function () {
        foreach (range(1, 5) as $_) {
            loginAs(browser(), password: 'wrong-password')->assertUnprocessable();
        }

        // Nem a senha correta passa durante o bloqueio.
        loginAs(browser())->assertTooManyRequests()->assertJsonPath('code', 'too_many_requests');

        // Outro e-mail do mesmo IP tem o próprio contador.
        User::factory()->create(['email' => 'other@example.com', 'password' => 'secret-password-1']);
        loginAs(browser(), 'other@example.com')->assertOk();
    });

    it('counts e-mails that differ only in case against the same limit', function () {
        foreach (['player@example.com', 'PLAYER@example.com', 'Player@Example.com', 'pLaYeR@example.com', 'PLAYER@EXAMPLE.COM'] as $email) {
            loginAs(browser(), $email, 'wrong-password')->assertUnprocessable();
        }

        loginAs(browser())->assertTooManyRequests();
    });

    it('rejects credentials that are not strings with 422, not 500', function (array $body, string $field) {
        $session = browser();
        $session->csrfCookie();

        $session->post('/api/v1/auth/login', $body)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors([$field]);
    })->with([
        'e-mail as list' => [['email' => ['player@example.com'], 'password' => 'x'], 'email'],
        'e-mail as object' => [['email' => ['a' => 'player@example.com'], 'password' => 'x'], 'email'],
        'password as list' => [['email' => 'player@example.com', 'password' => ['x']], 'password'],
    ]);
});

describe('remember me', function () {
    $recaller = fn () => auth()->guard('web')->getRecallerName();

    it('keeps the remember cookie for 30 days', function () use ($recaller) {
        $session = browser();
        $session->csrfCookie();

        $response = $session->post('/api/v1/auth/login', [
            'email' => 'player@example.com',
            'password' => 'secret-password-1',
            'remember' => true,
        ])->assertOk();

        $cookie = $response->getCookie($recaller(), decrypt: false);

        expect($cookie)->not->toBeNull()
            ->and($cookie->isHttpOnly())->toBeTrue()
            ->and($cookie->getExpiresTime() - time())->toBeGreaterThan(29 * 86400)->toBeLessThanOrEqual(30 * 86400 + 60);
    });

    it('does not set the remember cookie unless asked', function () use ($recaller) {
        $session = browser();

        $response = loginAs($session)->assertOk();

        expect($response->getCookie($recaller(), decrypt: false))->toBeNull();
    });

    it('clears the remember cookie on logout', function () use ($recaller) {
        $session = browser();
        $session->csrfCookie();
        $session->post('/api/v1/auth/login', [
            'email' => 'player@example.com',
            'password' => 'secret-password-1',
            'remember' => true,
        ])->assertOk();

        $response = $session->post('/api/v1/auth/logout')->assertNoContent();

        expect($response->getCookie($recaller(), decrypt: false)?->isCleared())->toBeTrue()
            ->and($session->cookie($recaller()))->toBeNull();
    });
});

describe('logout', function () {
    it('invalidates the session and rotates the CSRF token', function () {
        $session = browser();
        loginAs($session)->assertOk();
        $oldSession = $session->cookie('cs_tactical_session');
        $oldXsrf = $session->cookie('XSRF-TOKEN');

        $session->post('/api/v1/auth/logout')->assertNoContent();

        expect(decrypt($session->cookie('XSRF-TOKEN'), false))->not->toBe(decrypt($oldXsrf, false));
        $session->get('/api/v1/me')->assertUnauthorized();

        // O cookie antigo, guardado por um atacante, também não vale mais.
        FrontendSession::forgetRequestState();
        $this->withCredentials()
            ->withUnencryptedCookies(['cs_tactical_session' => $oldSession])
            ->withHeader('Origin', FrontendSession::ORIGIN)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    });

    it('requires the CSRF token', function () {
        $session = browser();
        loginAs($session)->assertOk();

        $session->withoutXsrfHeader()->post('/api/v1/auth/logout')->assertStatus(419);

        $session->get('/api/v1/me')->assertOk();
    });

    it('requires authentication', function () {
        $session = browser();
        $session->csrfCookie();

        $session->post('/api/v1/auth/logout')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
    });
});

describe('me', function () {
    it('returns 401 without a session', function () {
        $this->getJson('/api/v1/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'unauthenticated']);
    });

    it('returns 401 as JSON even without the Accept header', function () {
        $this->get('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    });

    it('returns 401 for a forged session cookie', function () {
        $this->withCredentials()
            ->withUnencryptedCookies(['cs_tactical_session' => 'forged'])
            ->withHeader('Origin', FrontendSession::ORIGIN)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    });

    it('does not authenticate with the session cookie from another origin', function () {
        $session = browser();
        loginAs($session)->assertOk();

        // Requisição cross-site: o Sanctum não trata a origem como frontend, então não há sessão.
        FrontendSession::forgetRequestState();
        $this->withCredentials()
            ->withUnencryptedCookies(['cs_tactical_session' => $session->cookie('cs_tactical_session')])
            ->withHeader('Origin', 'https://evil.example')
            ->getJson('/api/v1/me')
            ->assertUnauthorized();
    });

    it('does not accept bearer tokens', function () {
        $this->withToken('anything')->getJson('/api/v1/me')->assertUnauthorized();
    });

    it('returns only the expected fields', function () {
        $session = browser();
        loginAs($session)->assertOk();

        $data = $session->get('/api/v1/me')->assertOk()->json('data');

        expect(array_keys($data))->toBe(['id', 'name', 'email', 'email_verified', 'platform_role', 'profile', 'created_at'])
            ->and(array_keys($data['profile']))->toBe(['nickname', 'country_code', 'timezone', 'bio'])
            ->and($data['email_verified'])->toBeTrue()
            ->and($data['created_at'])->toEndWith('Z');
    });

    it('is limited to 120 requests per minute per user', function () {
        $session = browser();
        loginAs($session)->assertOk();

        foreach (range(1, 120) as $_) {
            $session->get('/api/v1/me')->assertOk();
        }

        $session->get('/api/v1/me')->assertTooManyRequests();
    });
});
