<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Models\UserSteamAccount;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrontendSession;

/*
| Vínculo da conta Steam por OpenID 2.0 (etapa M2, docs/training/mvp-m2-vinculo-steam.md).
| A Steam nunca é chamada de verdade: `Http::fake()` responde ao `check_authentication` e
| `preventStrayRequests()` reprova qualquer outra chamada.
*/

const STEAM_ENDPOINT = 'https://steamcommunity.com/openid/login';
const STEAM_FRONTEND = 'http://localhost:3000';
const STEAM_ID = '76561198000000001';

beforeEach(function () {
    config(['app.frontend_url' => STEAM_FRONTEND, 'steam.openid_endpoint' => STEAM_ENDPOINT]);
    Http::preventStrayRequests();

    $this->user = User::factory()->withProfile()->create(['email' => 'steam@example.com', 'password' => 'steam-password-1']);
});

function steamSession(string $email = 'steam@example.com', string $password = 'steam-password-1'): FrontendSession
{
    $session = (new FrontendSession(test()))->realCsrf();
    $session->csrfCookie();
    $session->post('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk();
    $session->get('/api/v1/me')->assertOk();

    return $session;
}

/** Inicia o vínculo e devolve o `state` que a API pôs na URL de volta. */
function steamStart(FrontendSession $session): string
{
    $url = $session->post('/api/v1/me/steam/link')->assertOk()->json('redirect_url');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    parse_str((string) parse_url($query['openid_return_to'], PHP_URL_QUERY), $returnQuery);

    return $returnQuery['state'];
}

/**
 * Resposta OpenID como a Steam manda (sem o prefixo `openid.`), válida para o `state`.
 *
 * @param  array<string, string|null>  $overrides  null remove o campo
 * @return array<string, string>
 */
function steamResponse(string $state, array $overrides = []): array
{
    $response = [
        'ns' => 'http://specs.openid.net/auth/2.0',
        'mode' => 'id_res',
        'op_endpoint' => STEAM_ENDPOINT,
        'claimed_id' => 'https://steamcommunity.com/openid/id/'.STEAM_ID,
        'identity' => 'https://steamcommunity.com/openid/id/'.STEAM_ID,
        'return_to' => STEAM_FRONTEND.'/painel/conta/steam?state='.$state,
        'response_nonce' => now()->utc()->format('Y-m-d\TH:i:s\Z').bin2hex(random_bytes(4)),
        'assoc_handle' => '1234567890',
        'signed' => 'signed,op_endpoint,claimed_id,identity,return_to,response_nonce,assoc_handle',
        'sig' => 'c2lnbmF0dXJh',
        ...$overrides,
    ];

    return array_filter($response, fn ($value) => $value !== null);
}

/** @param  array<string, string>  $openid */
function steamComplete(FrontendSession $session, ?string $state, array $openid): TestResponse
{
    return $session->post('/api/v1/me/steam/link/complete', ['state' => $state, 'openid' => $openid]);
}

function steamVerifies(bool $valid = true): void
{
    Http::fake([STEAM_ENDPOINT => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:".($valid ? 'true' : 'false')."\n")]);
}

/** Recusa com o `code` esperado, sem perguntar à Steam e sem gravar nada. */
function steamRejected(TestResponse $response, int $status, string $code): void
{
    $response->assertStatus($status)->assertJsonPath('code', $code);
    expect(UserSteamAccount::query()->count())->toBe(0);
}

describe('start', function () {
    it('requires a session and a CSRF token', function () {
        (new FrontendSession(test()))->post('/api/v1/me/steam/link')->assertUnauthorized();

        steamSession()->withoutXsrfHeader()->post('/api/v1/me/steam/link')->assertStatus(419);
    });

    it('builds the Steam login URL from the configuration, with a fresh state', function () {
        $session = steamSession();
        $url = $session->post('/api/v1/me/steam/link')->assertOk()->json('redirect_url');

        expect($url)->toStartWith(STEAM_ENDPOINT.'?');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        expect($query['openid_ns'])->toBe('http://specs.openid.net/auth/2.0')
            ->and($query['openid_mode'])->toBe('checkid_setup')
            ->and($query['openid_realm'])->toBe(STEAM_FRONTEND)
            ->and($query['openid_return_to'])->toMatch('#^http://localhost:3000/painel/conta/steam\?state=[0-9a-f]{64}$#')
            ->and($query['openid_identity'])->toBe('http://specs.openid.net/auth/2.0/identifier_select')
            ->and($query['openid_claimed_id'])->toBe('http://specs.openid.net/auth/2.0/identifier_select');

        expect(steamStart($session))->not->toBe(steamStart($session));
    });

    it('ignores the Host of the request when building the return URL', function () {
        $session = steamSession();
        $url = $session->post('http://evil.example/api/v1/me/steam/link')->assertOk()->json('redirect_url');

        expect(urldecode($url))->toContain('openid.return_to='.STEAM_FRONTEND.'/painel/conta/steam')
            ->and($url)->not->toContain('evil.example');
    });

    it('refuses to start when the account is already linked', function () {
        UserSteamAccount::factory()->for($this->user)->create();

        steamSession()->post('/api/v1/me/steam/link')->assertStatus(409)->assertJsonPath('code', 'steam_already_linked');
    });

    it('is limited to 10 requests per minute per user', function () {
        $this->freezeTime();
        $session = steamSession();

        foreach (range(1, 10) as $_) {
            $session->post('/api/v1/me/steam/link')->assertOk();
        }
        $session->post('/api/v1/me/steam/link')->assertStatus(429);
    });
});

describe('response validation (rules 2 to 8)', function () {
    beforeEach(fn () => steamVerifies());

    it('refuses a missing, different, expired or reused state', function () {
        $session = steamSession();

        $state = steamStart($session);
        steamRejected(steamComplete($session, null, steamResponse($state)), 422, 'steam_state_invalid');
        // Consumido na primeira tentativa, mesmo recusada: o mesmo state não serve mais.
        steamRejected(steamComplete($session, $state, steamResponse($state)), 422, 'steam_state_invalid');

        steamStart($session);
        $other = str_repeat('a', 64);
        steamRejected(steamComplete($session, $other, steamResponse($other)), 422, 'steam_state_invalid');

        $state = steamStart($session);
        $this->travel(11)->minutes();
        steamRejected(steamComplete($session, $state, steamResponse($state)), 422, 'steam_state_invalid');

        Http::assertNothingSent();
    });

    it('only accepts the state in the session that created it', function () {
        $state = steamStart(steamSession());
        $other = steamSession();

        steamRejected(steamComplete($other, $state, steamResponse($state)), 422, 'steam_state_invalid');
    });

    it('reports a cancellation on Steam', function () {
        $session = steamSession();
        $state = steamStart($session);

        steamRejected(steamComplete($session, $state, ['ns' => 'http://specs.openid.net/auth/2.0', 'mode' => 'cancel']), 422, 'steam_cancelled');
        Http::assertNothingSent();
    });

    it('refuses responses that do not match the Steam profile', function (array $overrides) {
        $session = steamSession();
        $state = steamStart($session);

        steamRejected(steamComplete($session, $state, steamResponse($state, $overrides)), 422, 'steam_response_invalid');
        Http::assertNothingSent();
    })->with([
        'modo desconhecido' => [['mode' => 'setup_needed']],
        'ns de outra versão' => [['ns' => 'http://openid.net/signon/1.1']],
        'op_endpoint de outro host' => [['op_endpoint' => 'https://evil.example/openid/login']],
        'return_to com outro state' => [['return_to' => STEAM_FRONTEND.'/painel/conta/steam?state='.str_repeat('b', 64)]],
        'return_to de outro host' => [['return_to' => 'https://evil.example/painel/conta/steam?state=x']],
        'claimed_id de outro host' => [['claimed_id' => 'https://evil.example/openid/id/'.STEAM_ID, 'identity' => 'https://evil.example/openid/id/'.STEAM_ID]],
        'claimed_id com host parecido' => [['claimed_id' => 'http://steamcommunity.com.evil.example/openid/id/'.STEAM_ID, 'identity' => 'http://steamcommunity.com.evil.example/openid/id/'.STEAM_ID]],
        'SteamID fora do formato' => [['claimed_id' => 'https://steamcommunity.com/openid/id/12345', 'identity' => 'https://steamcommunity.com/openid/id/12345']],
        'claimed_id diferente de identity' => [['identity' => 'https://steamcommunity.com/openid/id/76561198000000002']],
        'signed sem return_to' => [['signed' => 'signed,op_endpoint,claimed_id,identity,response_nonce,assoc_handle']],
        'signed sem response_nonce' => [['signed' => 'signed,op_endpoint,claimed_id,identity,return_to,assoc_handle']],
        'sem assinatura' => [['sig' => null]],
    ]);

    it('accepts the http:// claimed_id the Steam documentation shows', function () {
        $session = steamSession();
        $state = steamStart($session);
        $claimed = 'http://steamcommunity.com/openid/id/'.STEAM_ID;

        steamComplete($session, $state, steamResponse($state, ['claimed_id' => $claimed, 'identity' => $claimed]))
            ->assertOk()->assertJsonPath('steam.steam_id', STEAM_ID);
    });

    it('refuses a nonce already used, even in another session', function () {
        $first = steamSession();
        $state = steamStart($first);
        $nonce = now()->utc()->format('Y-m-d\TH:i:s\Z').'repetido';
        steamComplete($first, $state, steamResponse($state, ['response_nonce' => $nonce]))->assertOk();

        User::factory()->withProfile()->create(['email' => 'other@example.com', 'password' => 'other-password-1']);
        $second = steamSession('other@example.com', 'other-password-1');
        $state = steamStart($second);
        steamComplete($second, $state, steamResponse($state, ['response_nonce' => $nonce]))
            ->assertStatus(422)->assertJsonPath('code', 'steam_replay');
        expect(UserSteamAccount::query()->count())->toBe(1);
        Http::assertSentCount(1);
    });

    it('refuses a nonce dated outside the allowed window', function (string $nonce) {
        $session = steamSession();
        $state = steamStart($session);

        steamRejected(steamComplete($session, $state, steamResponse($state, ['response_nonce' => $nonce])), 422, 'steam_replay');
        Http::assertNothingSent();
    })->with([
        'antigo' => fn () => now()->subMinutes(6)->utc()->format('Y-m-d\TH:i:s\Z').'x',
        'futuro' => fn () => now()->addMinutes(6)->utc()->format('Y-m-d\TH:i:s\Z').'x',
        'sem data' => 'nao-tem-data',
    ]);

    it('refuses unknown openid fields and non-text values', function (array $openid) {
        $session = steamSession();
        $state = steamStart($session);

        steamComplete($session, $state, [...steamResponse($state), ...$openid])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        expect(UserSteamAccount::query()->count())->toBe(0);
        Http::assertNothingSent();
    })->with([
        'campo desconhecido' => [['ext1.value' => 'x']],
        'lista no lugar de texto' => [['claimed_id' => ['https://steamcommunity.com/openid/id/'.STEAM_ID]]],
    ]);
});

describe('verification with Steam (rule 9)', function () {
    it('sends exactly the fields received, with mode=check_authentication', function () {
        steamVerifies();
        $session = steamSession();
        $state = steamStart($session);
        $openid = steamResponse($state);

        steamComplete($session, $state, $openid)->assertOk();

        $expected = [];
        foreach ($openid as $field => $value) {
            $expected["openid.{$field}"] = $value;
        }
        $expected['openid.mode'] = 'check_authentication';
        Http::assertSent(fn (HttpRequest $request) => $request->url() === STEAM_ENDPOINT
            && $request->method() === 'POST'
            && $request->data() === $expected);
    });

    it('refuses a response that Steam does not confirm', function () {
        steamVerifies(false);
        $session = steamSession();
        $state = steamStart($session);

        steamRejected(steamComplete($session, $state, steamResponse($state)), 422, 'steam_not_verified');
    });

    it('answers 503 and stores nothing when Steam is down or slow', function () {
        Http::fake(fn () => throw new ConnectionException('timeout'));
        $session = steamSession();
        $state = steamStart($session);

        steamRejected(steamComplete($session, $state, steamResponse($state)), 503, 'steam_unavailable');
    });

    it('does not follow redirects from the verification endpoint', function () {
        Http::fake([
            STEAM_ENDPOINT => Http::response('', 302, ['Location' => 'https://evil.example/']),
            'evil.example/*' => Http::response("ns:http://specs.openid.net/auth/2.0\nis_valid:true\n"),
        ]);
        $session = steamSession();
        $state = steamStart($session);

        steamRejected(steamComplete($session, $state, steamResponse($state)), 503, 'steam_unavailable');
        Http::assertSentCount(1);
    });
});

describe('storing the link (rule 10)', function () {
    beforeEach(fn () => steamVerifies());

    it('links the Steam account and shows it in /me', function () {
        $this->freezeTime();
        $session = steamSession();
        $state = steamStart($session);

        steamComplete($session, $state, steamResponse($state))
            ->assertOk()
            ->assertExactJson(['steam' => ['steam_id' => STEAM_ID, 'linked_at' => now()->toIso8601ZuluString()]]);

        expect(UserSteamAccount::query()->where('user_id', $this->user->id)->value('steam_id'))->toBe(STEAM_ID);
        $session->get('/api/v1/me')->assertJsonPath('data.steam.steam_id', STEAM_ID);
    });

    it('refuses a Steam account linked to another user without saying which', function () {
        $owner = User::factory()->withProfile()->create(['email' => 'owner@example.com']);
        UserSteamAccount::factory()->for($owner)->create(['steam_id' => STEAM_ID]);
        $session = steamSession();
        $state = steamStart($session);

        $response = steamComplete($session, $state, steamResponse($state))->assertStatus(409)->assertJsonPath('code', 'steam_account_in_use');

        expect($response->getContent())->not->toContain('owner@example.com')->not->toContain($owner->id)
            ->and(UserSteamAccount::query()->where('user_id', $this->user->id)->exists())->toBeFalse();
    });

    it('refuses when the account got linked between start and completion', function () {
        $session = steamSession();
        $state = steamStart($session);
        UserSteamAccount::factory()->for($this->user)->create(['steam_id' => '76561198000000009']);

        steamComplete($session, $state, steamResponse($state))->assertStatus(409)->assertJsonPath('code', 'steam_already_linked');
        expect(UserSteamAccount::query()->where('user_id', $this->user->id)->value('steam_id'))->toBe('76561198000000009');
    });

    it('removes the link when the account is deleted', function () {
        UserSteamAccount::factory()->for($this->user)->create();

        $this->user->delete();

        expect(UserSteamAccount::query()->count())->toBe(0);
    });
});

describe('unlinking', function () {
    beforeEach(function () {
        UserSteamAccount::factory()->for($this->user)->create(['steam_id' => STEAM_ID]);
    });

    it('requires a session, a CSRF token and the current password', function () {
        (new FrontendSession(test()))->delete('/api/v1/me/steam')->assertUnauthorized();

        $session = steamSession();
        $session->withoutXsrfHeader()->delete('/api/v1/me/steam')->assertStatus(419);
        $session->withXsrfHeader();

        $session->delete('/api/v1/me/steam')->assertStatus(422)->assertJsonValidationErrors('current_password');
        steamDelete($session, 'senha-errada')->assertStatus(422)->assertJsonValidationErrors('current_password');
        steamDelete($session, ['steam-password-1'])->assertStatus(422)->assertJsonValidationErrors('current_password');

        expect(UserSteamAccount::query()->count())->toBe(1);
    });

    it('unlinks with the password and allows linking again', function () {
        steamVerifies();
        $session = steamSession();

        steamDelete($session, 'steam-password-1')->assertNoContent();
        expect(UserSteamAccount::query()->count())->toBe(0);
        $session->get('/api/v1/me')->assertJsonPath('data.steam', null);

        $state = steamStart($session);
        steamComplete($session, $state, steamResponse($state))->assertOk();
    });
});

/** DELETE com corpo (a senha atual), como faz o frontend. */
function steamDelete(FrontendSession $session, mixed $password): TestResponse
{
    return $session->delete('/api/v1/me/steam', ['current_password' => $password]);
}

describe('authorization', function () {
    it('protects the three routes with a session and a rate limit', function () {
        foreach (['v1.me.steam.link', 'v1.me.steam.link.complete', 'v1.me.steam.unlink'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();

            expect(collect($middleware)->contains('auth:sanctum'))->toBeTrue($name)
                ->and(collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue($name);
        }
    });

    it('lets each user manage only their own link, whatever the platform role', function (string $role) {
        $user = match ($role) {
            'user' => User::factory()->create(),
            'editor' => User::factory()->editor()->create(),
            'admin' => User::factory()->admin()->create(),
        };
        $other = User::factory()->create();

        expect(Gate::forUser($user)->allows('manageSteamLink', $user))->toBeTrue()
            ->and(Gate::forUser($user)->allows('manageSteamLink', $other))->toBeFalse();
    })->with(['user', 'editor', 'admin']);
});

describe('database', function () {
    it('enforces one Steam account per user, one user per Steam account and the SteamID64 format', function () {
        $other = User::factory()->create();
        UserSteamAccount::factory()->for($this->user)->create(['steam_id' => STEAM_ID]);

        // Cada inserção num savepoint: um erro aborta a transação do teste e mascararia o seguinte.
        $insert = fn (string $userId, string $steamId) => DB::transaction(fn () => DB::table('user_steam_accounts')->insert([
            'id' => (string) str()->uuid7(), 'user_id' => $userId, 'steam_id' => $steamId, 'linked_at' => now(),
        ]));

        expect(fn () => $insert($this->user->id, '76561198000000002'))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => $insert($other->id, STEAM_ID))->toThrow(UniqueConstraintViolationException::class)
            ->and(fn () => $insert($other->id, '12345678901234567'))->toThrow(QueryException::class);
    });

    it('cascades from the user and rolls back cleanly', function () {
        expect(DB::scalar("select confdeltype from pg_constraint where conname = 'user_steam_accounts_user_id_foreign'"))->toBe('c');

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        expect(Schema::hasTable('user_steam_accounts'))->toBeFalse();

        $this->artisan('migrate')->assertSuccessful();
        expect(Schema::hasTable('user_steam_accounts'))->toBeTrue();
    });
});

describe('configuration', function () {
    it('only lets the endpoint be overridden outside production', function () {
        $load = function (string $env): string {
            $backup = [$_ENV, $_SERVER];
            foreach ([&$_ENV, &$_SERVER] as &$bag) {
                $bag['APP_ENV'] = $env;
                $bag['STEAM_OPENID_ENDPOINT'] = 'http://steam-openid:8090/openid/login';
            }
            try {
                return (require config_path('steam.php'))['openid_endpoint'];
            } finally {
                [$_ENV, $_SERVER] = $backup;
            }
        };

        expect($load('production'))->toBe(STEAM_ENDPOINT)
            ->and($load('e2e'))->toBe('http://steam-openid:8090/openid/login');
    });
});
