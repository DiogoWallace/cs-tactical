<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrontendSession;

/*
| Recuperação e redefinição de senha (etapa 1.4, ADR 0010).
| A API nunca revela se um e-mail tem conta; tokens expiram em 60 minutos e são de uso único.
*/

beforeEach(function () {
    config(['app.frontend_url' => 'http://localhost:3000']);
    Notification::fake();

    $this->user = User::factory()->withProfile()->create([
        'email' => 'player@example.com',
        'password' => 'old-password-1',
    ]);
});

function guest(): FrontendSession
{
    return new FrontendSession(test());
}

function requestReset(string $email = 'player@example.com'): TestResponse
{
    return guest()->post('/api/v1/auth/forgot-password', ['email' => $email]);
}

/** Token do último e-mail de redefinição enviado ao usuário. */
function sentToken(User $user): string
{
    $token = null;
    Notification::assertSentTo($user, ResetPasswordNotification::class, function ($notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return $token;
}

function resetPassword(string $token, array $overrides = []): TestResponse
{
    return guest()->post('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'player@example.com',
        'password' => 'new-password-9',
        'password_confirmation' => 'new-password-9',
        ...$overrides,
    ]);
}

describe('forgot password', function () {
    it('sends a queued e-mail with a link to the frontend page', function () {
        requestReset()->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordNotification::class, function ($notification) {
            $mail = $notification->toMail($this->user);

            return str_starts_with($mail->actionUrl, 'http://localhost:3000/redefinir-senha#token=')
                && str_contains($mail->actionUrl, '&email=player%40example.com')
                && $mail->subject === 'Redefina sua senha';
        });
        expect(new ResetPasswordNotification('x'))->toBeInstanceOf(ShouldQueue::class);
    });

    it('keeps the token out of the part of the URL sent to servers', function () {
        requestReset()->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordNotification::class, function ($notification) {
            $url = $notification->toMail($this->user)->actionUrl;

            return ! str_contains(strtok($url, '#'), $notification->token);
        });
    });

    it('answers the same way for an unknown e-mail', function () {
        $known = requestReset()->assertOk()->json();
        $unknown = requestReset('nobody@example.com')->assertOk()->json();

        expect($unknown)->toBe($known)
            ->and($known['message'])->toBe('Se houver uma conta com este e-mail, enviaremos um link para redefinir a senha.');
        Notification::assertSentToTimes($this->user, ResetPasswordNotification::class, 1);
    });

    it('answers the same way when asked again within a minute, without sending twice', function () {
        $first = requestReset()->assertOk()->json();
        $second = requestReset()->assertOk()->json();

        expect($second)->toBe($first);
        Notification::assertSentToTimes($this->user, ResetPasswordNotification::class, 1);
    });

    it('accepts the e-mail in any case', function () {
        requestReset(' PLAYER@Example.com ')->assertOk();

        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
    });

    it('validates the e-mail format', function () {
        requestReset('not-an-email')->assertUnprocessable()->assertJsonValidationErrors(['email']);
    });

    it('is limited to 5 requests per minute per e-mail and IP', function () {
        foreach (range(1, 5) as $_) {
            requestReset()->assertOk();
        }

        requestReset()->assertTooManyRequests();
    });

    it('rejects requests while authenticated', function () {
        $this->actingAs($this->user)->withHeader('Origin', FrontendSession::ORIGIN)
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'player@example.com'])
            ->assertStatus(409);
    });
});

describe('reset password', function () {
    it('changes the password with a valid token', function () {
        requestReset();

        resetPassword(sentToken($this->user))
            ->assertOk()
            ->assertJsonPath('message', 'Sua senha foi redefinida. Entre com a nova senha.');

        expect(Hash::check('new-password-9', $this->user->fresh()->password))->toBeTrue();
    });

    it('lets the user log in with the new password only', function () {
        requestReset();
        resetPassword(sentToken($this->user))->assertOk();

        $session = guest();
        $session->post('/api/v1/auth/login', ['email' => 'player@example.com', 'password' => 'old-password-1'])
            ->assertUnprocessable();
        $session->post('/api/v1/auth/login', ['email' => 'player@example.com', 'password' => 'new-password-9'])
            ->assertOk();
    });

    it('does not log the user in', function () {
        requestReset();
        $session = guest();
        $session->post('/api/v1/auth/reset-password', [
            'token' => sentToken($this->user),
            'email' => 'player@example.com',
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertOk();

        $session->get('/api/v1/me')->assertUnauthorized();
    });

    it('accepts each token only once', function () {
        requestReset();
        $token = sentToken($this->user);

        resetPassword($token)->assertOk();
        resetPassword($token, ['password' => 'another-pass-7', 'password_confirmation' => 'another-pass-7'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['token']);

        expect(DB::table('password_reset_tokens')->count())->toBe(0);
    });

    it('rejects a token after 60 minutes', function () {
        requestReset();
        $token = sentToken($this->user);

        $this->travel(61)->minutes();

        resetPassword($token)->assertUnprocessable()->assertJsonValidationErrors(['token']);
        expect(Hash::check('old-password-1', $this->user->fresh()->password))->toBeTrue();
    });

    it('invalidates the previous token when a new one is requested', function () {
        requestReset();
        $old = sentToken($this->user);

        $this->travel(2)->minutes();
        Notification::fake();
        requestReset()->assertOk();
        $new = sentToken($this->user);

        resetPassword($old)->assertUnprocessable()->assertJsonValidationErrors(['token']);
        resetPassword($new)->assertOk();
    });

    it('gives the same answer for a wrong token and an unknown e-mail', function () {
        requestReset();
        $token = sentToken($this->user);

        $wrongToken = resetPassword(str_repeat('a', 64))->assertUnprocessable()->json();
        $unknownEmail = resetPassword($token, ['email' => 'nobody@example.com'])->assertUnprocessable()->json();

        expect($unknownEmail)->toBe($wrongToken)
            ->and($wrongToken['errors'])->toBe(['token' => ['Este link de redefinição é inválido ou expirou. Solicite um novo.']]);
    });

    it('does not accept a token issued for another account', function () {
        $other = User::factory()->create(['email' => 'other@example.com']);
        requestReset('other@example.com');

        resetPassword(sentToken($other))->assertUnprocessable()->assertJsonValidationErrors(['token']);
        expect(Hash::check('old-password-1', $this->user->fresh()->password))->toBeTrue();
    });

    it('validates the new password and keeps the token usable', function (array $overrides) {
        requestReset();
        $token = sentToken($this->user);

        resetPassword($token, $overrides)->assertUnprocessable()->assertJsonValidationErrors(['password']);
        resetPassword($token)->assertOk();
    })->with([
        'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
        'not confirmed' => [['password_confirmation' => 'something-else']],
    ]);

    it('rotates the remember token', function () {
        $before = $this->user->remember_token;
        requestReset();

        resetPassword(sentToken($this->user))->assertOk();

        expect($this->user->fresh()->remember_token)->not->toBe($before);
    });

    it('signs out the sessions that were open with the old password', function () {
        $session = (new FrontendSession(test()))->realCsrf();
        $session->csrfCookie();
        $session->post('/api/v1/auth/login', ['email' => 'player@example.com', 'password' => 'old-password-1'])->assertOk();
        $session->get('/api/v1/me')->assertOk();

        // Outro navegador redefine a senha (fora da verificação CSRF real desta sessão).
        $token = Password::broker()->createToken($this->user);
        app()->instance('env', 'testing');
        resetPassword($token)->assertOk();

        app()->instance('env', 'local');
        $session->get('/api/v1/me')->assertUnauthorized();
    });

    it('rejects a token sent in another format', function () {
        guest()->post('/api/v1/auth/reset-password', [
            'token' => ['x'],
            'email' => 'player@example.com',
            'password' => 'new-password-9',
            'password_confirmation' => 'new-password-9',
        ])->assertUnprocessable()->assertJsonValidationErrors(['token']);
    });
});
