<?php

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ConfirmNewEmailNotification;
use App\Modules\Identity\Notifications\EmailChangedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrontendSession;
use Tests\TestCase;

/*
| Troca de e-mail com confirmação no novo endereço (etapa 1.5, ADR 0011).
*/

const CONFIRM_PAGE = 'http://localhost:3000/confirmar-email/';
const CONFIRM_API = '/api/v1/me/email/confirm/';

beforeEach(function () {
    config(['app.frontend_url' => 'http://localhost:3000', 'sanctum.stateful' => ['localhost:3000']]);
    Notification::fake();
    $this->user = User::factory()->withProfile()->create([
        'email' => 'old@example.com',
        'password' => 'secret-password-1',
    ]);
});

function asOwner(User $user): TestCase
{
    return test()->actingAs($user)->withHeader('Origin', FrontendSession::ORIGIN);
}

function requestChange(User $user, string $email = 'new@example.com', string $password = 'secret-password-1'): TestResponse
{
    return asOwner($user)->postJson('/api/v1/me/email', ['email' => $email, 'current_password' => $password]);
}

/** Link enviado ao novo endereço no último pedido. */
function confirmationLink(string $to = 'new@example.com'): string
{
    $link = null;
    Notification::assertSentTo(
        new AnonymousNotifiable,
        ConfirmNewEmailNotification::class,
        function ($notification, $channels, $notifiable) use ($to, &$link) {
            if (($notifiable->routes['mail'] ?? null) !== $to) {
                return false;
            }
            $link = $notification->toMail($notifiable)->actionUrl;

            return true;
        },
    );

    return $link;
}

/** Outro navegador: sem o usuário autenticado e a sessão guardados em memória pelo teste. */
function freshRequest(): void
{
    FrontendSession::forgetRequestState();
    test()->flushSession();
}

function confirmPath(string $link): string
{
    return CONFIRM_API.Str::after($link, CONFIRM_PAGE);
}

describe('request', function () {
    it('keeps the current e-mail and stores the new one as pending', function () {
        requestChange($this->user, ' NEW@Example.com ')
            ->assertStatus(202)
            ->assertJsonPath('data.email', 'old@example.com')
            ->assertJsonPath('data.pending_email', 'new@example.com');

        expect($this->user->fresh()->email)->toBe('old@example.com');
    });

    it('sends a queued confirmation link to the new address only', function () {
        requestChange($this->user)->assertStatus(202);

        $link = confirmationLink();
        expect($link)->toStartWith(CONFIRM_PAGE.$this->user->id.'/'.hash('sha256', 'new@example.com').'?expires=')
            ->and($link)->toContain('&signature=')
            ->and(new ConfirmNewEmailNotification('x'))->toBeInstanceOf(ShouldQueue::class);
        Notification::assertNotSentTo($this->user, ConfirmNewEmailNotification::class);
    });

    it('requires the current password', function () {
        requestChange($this->user, password: 'wrong-password')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password']);

        expect($this->user->fresh()->pending_email)->toBeNull();
        Notification::assertNothingSent();
    });

    it('rejects an address used by another account, in any case', function () {
        User::factory()->create(['email' => 'taken@example.com']);

        requestChange($this->user, 'TAKEN@example.com')
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Este e-mail já está em uso.');
    });

    it('rejects the current address', function () {
        requestChange($this->user, 'OLD@example.com')
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Informe um e-mail diferente do atual.');
    });

    it('validates the format', function () {
        requestChange($this->user, 'not-an-email')->assertUnprocessable()->assertJsonValidationErrors(['email']);
    });

    it('answers 422, not 500, when the fields are not strings', function (array $payload, string $field) {
        asOwner($this->user)->postJson('/api/v1/me/email', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect($this->user->fresh()->pending_email)->toBeNull();
    })->with([
        'current password as a list' => [['email' => 'new@example.com', 'current_password' => ['secret-password-1']], 'current_password'],
        'email as a list' => [['email' => ['new@example.com'], 'current_password' => 'secret-password-1'], 'email'],
    ]);

    it('can be cancelled', function () {
        requestChange($this->user)->assertStatus(202);

        asOwner($this->user)->deleteJson('/api/v1/me/email')->assertOk()->assertJsonPath('data.pending_email', null);

        expect($this->user->fresh()->pending_email)->toBeNull();
    });

    it('requires authentication', function () {
        $this->withHeader('Origin', FrontendSession::ORIGIN)
            ->postJson('/api/v1/me/email', ['email' => 'new@example.com', 'current_password' => 'x'])
            ->assertUnauthorized();
    });

    it('requires the CSRF token', function () {
        $session = (new FrontendSession(test()))->realCsrf();
        $session->csrfCookie();
        $session->post('/api/v1/auth/login', ['email' => 'old@example.com', 'password' => 'secret-password-1'])->assertOk();

        $session->withoutXsrfHeader()
            ->post('/api/v1/me/email', ['email' => 'new@example.com', 'current_password' => 'secret-password-1'])
            ->assertStatus(419);
    });
});

describe('confirmation', function () {
    it('switches the e-mail, marks it verified and warns the old address', function () {
        $this->user->forceFill(['email_verified_at' => null])->save();
        requestChange($this->user);

        asOwner($this->user)->getJson(confirmPath(confirmationLink()))
            ->assertOk()
            ->assertJsonPath('data.email', 'new@example.com')
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.pending_email', null);

        Notification::assertSentTo(new AnonymousNotifiable, EmailChangedNotification::class, function ($notification, $channels, $notifiable) {
            $mail = $notification->toMail($notifiable);

            return $notifiable->routes['mail'] === 'old@example.com'
                && str_contains(implode(' ', $mail->introLines), 'ne*@example.com');
        });
    });

    it('lets the user log in with the new e-mail only', function () {
        requestChange($this->user);
        asOwner($this->user)->getJson(confirmPath(confirmationLink()))->assertOk();

        $session = new FrontendSession(test());
        $session->post('/api/v1/auth/login', ['email' => 'old@example.com', 'password' => 'secret-password-1'])->assertUnprocessable();
        $session->post('/api/v1/auth/login', ['email' => 'new@example.com', 'password' => 'secret-password-1'])->assertOk();
    });

    it('deletes pending password reset tokens of the old address', function () {
        Password::broker()->createToken($this->user);
        requestChange($this->user);

        asOwner($this->user)->getJson(confirmPath(confirmationLink()))->assertOk();

        expect(DB::table('password_reset_tokens')->count())->toBe(0);
    });

    it('rejects a link after 60 minutes', function () {
        requestChange($this->user);
        $path = confirmPath(confirmationLink());

        $this->travel(61)->minutes();

        asOwner($this->user)->getJson($path)->assertForbidden();
        expect($this->user->fresh()->email)->toBe('old@example.com');
    });

    it('rejects a tampered link', function () {
        requestChange($this->user);
        $path = preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), confirmPath(confirmationLink()));

        asOwner($this->user)->getJson($path)->assertForbidden();
    });

    it('invalidates older links when another address is requested', function () {
        requestChange($this->user, 'first@example.com');
        $old = confirmPath(confirmationLink('first@example.com'));
        requestChange($this->user, 'second@example.com');

        asOwner($this->user)->getJson($old)->assertForbidden();
        expect($this->user->fresh()->email)->toBe('old@example.com');
    });

    it('rejects a link after the request was cancelled', function () {
        requestChange($this->user);
        $path = confirmPath(confirmationLink());
        asOwner($this->user)->deleteJson('/api/v1/me/email')->assertOk();

        asOwner($this->user)->getJson($path)->assertForbidden();
    });

    it('does not change another account with its link', function () {
        requestChange($this->user);
        $intruder = User::factory()->withProfile()->create();
        freshRequest();

        asOwner($intruder)->getJson(confirmPath(confirmationLink()))->assertForbidden();
        expect($this->user->fresh()->email)->toBe('old@example.com')
            ->and($intruder->fresh()->email)->not->toBe('new@example.com');
    });

    it('requires a session', function () {
        requestChange($this->user);
        freshRequest();

        $this->withHeader('Origin', FrontendSession::ORIGIN)->getJson(confirmPath(confirmationLink()))->assertUnauthorized();
    });

    it('fails when the address was taken in the meantime', function () {
        requestChange($this->user);
        $path = confirmPath(confirmationLink());
        User::factory()->create(['email' => 'new@example.com']);

        asOwner($this->user)->getJson($path)->assertUnprocessable()->assertJsonValidationErrors(['email']);
        expect($this->user->fresh()->email)->toBe('old@example.com');
    });
});

it('masks the new address in the warning', function (string $email, string $masked) {
    expect(EmailChangedNotification::mask($email))->toBe($masked);
})->with([
    ['jogador@exemplo.com', 'jo*****@exemplo.com'],
    ['ab@x.com', 'a*@x.com'],
    ['a@x.com', 'a*@x.com'],
]);
