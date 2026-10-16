<?php

use App\Modules\Identity\Http\Middleware\EnsureEmailIsVerified;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\FrontendSession;
use Tests\TestCase;

/*
| Verificação de e-mail (etapa 1.4, ADR 0010): link assinado e relativo, aberto pelo frontend
| em /verificar-email/..., que chama a API com o mesmo caminho e a mesma query.
*/

const VERIFY_PAGE = 'http://localhost:3000/verificar-email/';
const VERIFY_API = '/api/v1/auth/email/verify/';

beforeEach(function () {
    config(['app.frontend_url' => 'http://localhost:3000', 'sanctum.stateful' => ['localhost:3000']]);
    Notification::fake();
});

/** Link enviado por e-mail ao usuário. */
function verificationLink(User $user): string
{
    $user->sendEmailVerificationNotification();

    $url = null;
    Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user, &$url) {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    return $url;
}

/** O que a página do frontend chama na API a partir do link. */
function apiPathFrom(string $link): string
{
    return VERIFY_API.Str::after($link, VERIFY_PAGE);
}

function asFrontendUser(User $user): TestCase
{
    return test()->actingAs($user)->withHeader('Origin', FrontendSession::ORIGIN);
}

describe('e-mail', function () {
    it('is sent through the queue when a user registers', function () {
        (new FrontendSession(test()))->post('/api/v1/auth/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
            'nickname' => 'AnaAWP',
        ])->assertCreated();

        $user = User::query()->whereEmailIs('ana@example.com')->sole();

        Notification::assertSentTo($user, VerifyEmailNotification::class);
        expect(new VerifyEmailNotification)->toBeInstanceOf(ShouldQueue::class);
    });

    it('links to the frontend page with a relative signed path', function () {
        $user = User::factory()->unverified()->create();

        $link = verificationLink($user);

        expect($link)->toStartWith(VERIFY_PAGE.$user->id.'/'.sha1($user->email).'?expires=')
            ->and($link)->toContain('&signature=')
            ->and($link)->not->toContain('/api/');
    });

    it('is written in Portuguese', function () {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);

            return $mail->subject === 'Confirme seu e-mail' && $mail->actionText === 'Confirmar e-mail';
        });
    });
});

describe('verification', function () {
    it('verifies the logged in user and fires the Verified event', function () {
        Event::fake([Verified::class]);
        $user = User::factory()->unverified()->create();

        asFrontendUser($user)->getJson(apiPathFrom(verificationLink($user)))->assertNoContent();

        expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
        Event::assertDispatched(Verified::class);
    });

    it('is idempotent for an already verified user', function () {
        $user = User::factory()->unverified()->create();
        $path = apiPathFrom(verificationLink($user));

        asFrontendUser($user)->getJson($path)->assertNoContent();
        asFrontendUser($user)->getJson($path)->assertNoContent();
    });

    it('requires a session', function () {
        $user = User::factory()->unverified()->create();

        $this->withHeader('Origin', FrontendSession::ORIGIN)
            ->getJson(apiPathFrom(verificationLink($user)))
            ->assertUnauthorized();

        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('rejects a link that expired after 60 minutes', function () {
        $user = User::factory()->unverified()->create();
        $path = apiPathFrom(verificationLink($user));

        $this->travel(61)->minutes();

        asFrontendUser($user)->getJson($path)->assertForbidden()->assertJsonPath('code', 'forbidden');
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('rejects a tampered link', function (string $tampering) {
        $user = User::factory()->unverified()->create();
        $path = apiPathFrom(verificationLink($user));

        $tampered = match ($tampering) {
            'signature' => preg_replace('/signature=[0-9a-f]+/', 'signature='.str_repeat('0', 64), $path),
            'expiry extended' => preg_replace('/expires=\d+/', 'expires='.(time() + 86400), $path),
            'no signature' => Str::before($path, '?'),
        };

        asFrontendUser($user)->getJson($tampered)->assertForbidden();
        expect($user->fresh()->hasVerifiedEmail())->toBeFalse();
    })->with(['signature', 'expiry extended', 'no signature']);

    it('does not verify another account with its link', function () {
        $owner = User::factory()->unverified()->create();
        $intruder = User::factory()->unverified()->create();

        asFrontendUser($intruder)->getJson(apiPathFrom(verificationLink($owner)))->assertForbidden();

        expect($owner->fresh()->hasVerifiedEmail())->toBeFalse()
            ->and($intruder->fresh()->hasVerifiedEmail())->toBeFalse();
    });

    it('works end to end with a real browser session', function () {
        $session = (new FrontendSession(test()))->realCsrf();
        $session->csrfCookie();
        $session->post('/api/v1/auth/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'correct-horse-9',
            'password_confirmation' => 'correct-horse-9',
            'nickname' => 'AnaAWP',
        ])->assertCreated()->assertJsonPath('data.email_verified', false);

        $user = User::query()->whereEmailIs('ana@example.com')->sole();
        $link = null;
        Notification::assertSentTo($user, VerifyEmailNotification::class, function ($notification) use ($user, &$link) {
            $link = $notification->toMail($user)->actionUrl;

            return true;
        });

        $session->get(apiPathFrom($link))->assertNoContent();
        $session->get('/api/v1/me')->assertJsonPath('data.email_verified', true);
    });
});

describe('resend', function () {
    it('sends a new link to an unverified user', function () {
        $user = User::factory()->unverified()->create();

        asFrontendUser($user)->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    });

    it('does nothing for a verified user', function () {
        $user = User::factory()->create();

        asFrontendUser($user)->postJson('/api/v1/auth/email/verification-notification')->assertNoContent();

        Notification::assertNothingSent();
    });

    it('requires a session', function () {
        $this->withHeader('Origin', FrontendSession::ORIGIN)
            ->postJson('/api/v1/auth/email/verification-notification')
            ->assertUnauthorized();
    });

    it('is limited to 6 requests per minute per user', function () {
        $user = User::factory()->unverified()->create();

        foreach (range(1, 6) as $_) {
            asFrontendUser($user)->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202);
        }

        asFrontendUser($user)->postJson('/api/v1/auth/email/verification-notification')
            ->assertTooManyRequests();
    });
});

describe('verified middleware', function () {
    beforeEach(function () {
        Route::middleware(['api', 'auth:sanctum', EnsureEmailIsVerified::class])
            ->get('/api/v1/__verified-probe', fn () => ['ok' => true]);
    });

    it('blocks unverified users with a stable code', function () {
        asFrontendUser(User::factory()->unverified()->create())
            ->getJson('/api/v1/__verified-probe')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Your email address is not verified.', 'code' => 'email_not_verified']);
    });

    it('lets verified users through', function () {
        asFrontendUser(User::factory()->create())->getJson('/api/v1/__verified-probe')->assertOk();
    });
});
