<?php

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrontendSession;

/*
| Troca de senha (etapa 1.5): PUT /api/v1/me/password, exige a senha atual, mantém a sessão
| atual e encerra as demais.
*/

beforeEach(function () {
    $this->user = User::factory()->withProfile()->create([
        'email' => 'player@example.com',
        'password' => 'old-password-1',
    ]);
});

function signedIn(bool $remember = false): FrontendSession
{
    $session = (new FrontendSession(test()))->realCsrf();
    $session->csrfCookie();
    $session->post('/api/v1/auth/login', [
        'email' => 'player@example.com',
        'password' => 'old-password-1',
        'remember' => $remember,
    ])->assertOk();
    $session->get('/api/v1/me')->assertOk(); // grava o hash da senha na sessão (Sanctum)

    return $session;
}

function changePassword(FrontendSession $session, array $overrides = []): TestResponse
{
    return $session->put('/api/v1/me/password', [
        'current_password' => 'old-password-1',
        'password' => 'new-password-9',
        'password_confirmation' => 'new-password-9',
        ...$overrides,
    ]);
}

it('changes the password', function () {
    changePassword(signedIn())->assertNoContent();

    expect(Hash::check('new-password-9', $this->user->fresh()->password))->toBeTrue();
});

it('keeps the current session and renews its id', function () {
    $session = signedIn();
    $before = decrypt($session->cookie('cs_tactical_session'), false);

    changePassword($session)->assertNoContent();

    expect(decrypt($session->cookie('cs_tactical_session'), false))->not->toBe($before);
    $session->get('/api/v1/me')->assertOk();
});

it('signs out the other sessions', function () {
    $other = signedIn();
    $current = signedIn();

    changePassword($current)->assertNoContent();

    $other->get('/api/v1/me')->assertUnauthorized();
    $current->get('/api/v1/me')->assertOk();
});

it('invalidates remember-me cookies of other devices and reissues the current one', function () {
    $before = $this->user->fresh()->remember_token;
    $session = signedIn(remember: true);
    $recaller = auth()->guard('web')->getRecallerName();
    $oldRecaller = $session->cookie($recaller);

    changePassword($session)->assertNoContent();

    expect($this->user->fresh()->remember_token)->not->toBe($before)
        ->and($session->cookie($recaller))->not->toBeNull()->not->toBe($oldRecaller);
});

it('stops the remember-me cookie of another device from restoring the session', function () {
    // Regressão (revisão do PR #3): o cookie persistente antigo, sozinho, não pode autenticar
    // depois da troca de senha. Fluxo real: sessão no Redis, CSRF real e cookies do navegador.
    $recaller = auth()->guard('web')->getRecallerName();

    // 1. Dois dispositivos: o principal sem "lembrar-me" e o segundo com.
    $main = signedIn();
    $second = signedIn(remember: true);
    expect($second->cookie($recaller))->not->toBeNull();

    // 2. Antes da troca, o cookie persistente sozinho (sem cookie de sessão) restaura a sessão.
    $second->withOnlyCookies($recaller, 'XSRF-TOKEN')
        ->get('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $this->user->id);

    // Cópia intacta do cookie antigo, para o passo 6.
    $oldRememberOnly = $second->withOnlyCookies($recaller, 'XSRF-TOKEN');

    // 3. Troca de senha no dispositivo principal.
    changePassword($main)->assertNoContent();

    // 4. A sessão atual continua válida.
    $main->get('/api/v1/me')->assertOk()->assertJsonPath('data.id', $this->user->id);

    // 5. A sessão do segundo dispositivo foi invalidada.
    $second->get('/api/v1/me')->assertUnauthorized();

    // 6. O cookie persistente antigo, isoladamente, não restaura a autenticação.
    $oldRememberOnly->get('/api/v1/me')->assertUnauthorized();

    // E o principal continua funcionando depois das tentativas do outro dispositivo.
    $main->get('/api/v1/me')->assertOk();
});

it('deletes pending password reset tokens', function () {
    $session = signedIn();
    Password::broker()->createToken($this->user);

    changePassword($session)->assertNoContent();

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('rejects a wrong current password', function () {
    changePassword(signedIn(), ['current_password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('old-password-1', $this->user->fresh()->password))->toBeTrue();
});

it('validates the new password', function (array $overrides) {
    changePassword(signedIn(), $overrides)->assertUnprocessable()->assertJsonValidationErrors(['password']);
})->with([
    'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
    'not confirmed' => [['password_confirmation' => 'other-password-9']],
    'same as current' => [['password' => 'old-password-1', 'password_confirmation' => 'old-password-1']],
]);

it('requires the CSRF token', function () {
    changePassword(signedIn()->withoutXsrfHeader())->assertStatus(419);
});

it('requires authentication', function () {
    $this->withHeader('Origin', FrontendSession::ORIGIN)
        ->putJson('/api/v1/me/password', ['current_password' => 'x', 'password' => 'y', 'password_confirmation' => 'y'])
        ->assertUnauthorized();
});

it('is limited to 5 attempts per minute', function () {
    $session = signedIn();

    foreach (range(1, 5) as $_) {
        changePassword($session, ['current_password' => 'wrong-password'])->assertUnprocessable();
    }

    changePassword($session)->assertTooManyRequests();
});

it('answers in Portuguese', function () {
    app()->setLocale('pt_BR');

    changePassword(signedIn(), ['current_password' => 'wrong-password'])
        ->assertJsonPath('errors.current_password.0', 'A senha atual está incorreta.');
    changePassword(signedIn(), ['password' => 'old-password-1', 'password_confirmation' => 'old-password-1'])
        ->assertJsonPath('errors.password.0', 'A nova senha deve ser diferente da atual.');
});
