<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\CountryCodes;
use Illuminate\Support\Facades\Auth;
use Tests\Support\FrontendSession;

/** @return array<string, mixed> */
function registration(array $overrides = []): array
{
    return [
        'name' => 'Ana Souza',
        'email' => 'ana@example.com',
        'password' => 'correct-horse-9',
        'password_confirmation' => 'correct-horse-9',
        'nickname' => 'AnaAWP',
        'country_code' => 'BR',
        'timezone' => 'America/Sao_Paulo',
        ...$overrides,
    ];
}

function frontend(): FrontendSession
{
    return new FrontendSession(test());
}

it('creates the user and the profile and starts a session', function () {
    $session = frontend();

    $session->post('/api/v1/auth/register', registration())
        ->assertCreated()
        ->assertJsonPath('data.email', 'ana@example.com')
        ->assertJsonPath('data.platform_role', 'user')
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonPath('data.profile.nickname', 'AnaAWP')
        ->assertJsonPath('data.profile.country_code', 'BR')
        ->assertJsonPath('data.profile.timezone', 'America/Sao_Paulo');

    $user = User::query()->whereEmailIs('ana@example.com')->sole();
    expect($user->profile)->toBeInstanceOf(Profile::class);

    $session->get('/api/v1/me')->assertOk()->assertJsonPath('data.id', $user->id);
});

it('never returns secrets', function () {
    $response = frontend()->post('/api/v1/auth/register', registration())->assertCreated();

    expect($response->json('data'))->not->toHaveKeys(['password', 'remember_token', 'email_verified_at'])
        ->and($response->getContent())->not->toContain('correct-horse-9');
});

it('ignores a platform role or verification date sent by the client', function () {
    frontend()->post('/api/v1/auth/register', registration([
        'platform_role' => 'admin',
        'email_verified_at' => now()->toIso8601String(),
        'id' => '0199f1c2-0000-7000-8000-000000000000',
    ]))->assertCreated()->assertJsonPath('data.platform_role', 'user');

    $user = User::query()->whereEmailIs('ana@example.com')->sole();

    expect($user->platform_role)->toBe(PlatformRole::User)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->id)->not->toBe('0199f1c2-0000-7000-8000-000000000000');
});

it('normalizes e-mail, nickname spacing and country code', function () {
    frontend()->post('/api/v1/auth/register', registration([
        'email' => '  Ana@Example.COM ',
        'nickname' => ' AnaAWP ',
        'country_code' => 'br',
    ]))->assertCreated();

    $user = User::query()->whereEmailIs('ana@example.com')->sole();
    expect($user->email)->toBe('ana@example.com')
        ->and($user->profile->nickname)->toBe('AnaAWP')
        ->and($user->profile->country_code)->toBe('BR');
});

it('defaults the timezone to UTC and accepts no country', function () {
    frontend()->post('/api/v1/auth/register', registration(['timezone' => null, 'country_code' => null]))
        ->assertCreated()
        ->assertJsonPath('data.profile.timezone', 'UTC')
        ->assertJsonPath('data.profile.country_code', null);
});

it('rejects an e-mail already in use, in any case', function () {
    User::factory()->create(['email' => 'ana@example.com']);

    frontend()->post('/api/v1/auth/register', registration(['email' => 'ANA@example.com']))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['email']);
});

it('rejects a nickname already in use, in any case', function () {
    Profile::factory()->create(['nickname' => 'AnaAWP']);

    frontend()->post('/api/v1/auth/register', registration(['nickname' => 'anaawp']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['nickname']);
});

it('rejects fields that are not strings with 422, not 500', function () {
    frontend()->post('/api/v1/auth/register', registration([
        'name' => ['Ana'],
        'email' => ['ana@example.com'],
        'nickname' => ['a' => 'AnaAWP'],
        'country_code' => ['BR'],
        'timezone' => ['UTC'],
    ]))
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['name', 'email', 'nickname', 'country_code', 'timezone']);

    expect(User::query()->count())->toBe(0);
});

it('validates the fields', function (array $overrides, string $field) {
    frontend()->post('/api/v1/auth/register', registration($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(User::query()->count())->toBe(0)
        ->and(Profile::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'blank name' => [['name' => '   '], 'name'],
    'invalid e-mail' => [['email' => 'not-an-email'], 'email'],
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'password over 72 characters' => [['password' => str_repeat('a', 73), 'password_confirmation' => str_repeat('a', 73)], 'password'],
    'unconfirmed password' => [['password_confirmation' => 'something-else'], 'password'],
    'missing nickname' => [['nickname' => ''], 'nickname'],
    'short nickname' => [['nickname' => 'ab'], 'nickname'],
    'nickname with space' => [['nickname' => 'two words'], 'nickname'],
    'nickname with accent' => [['nickname' => 'jogadôr'], 'nickname'],
    'unknown country' => [['country_code' => 'XX'], 'country_code'],
    'country name' => [['country_code' => 'Brazil'], 'country_code'],
    'invalid timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'timezone offset' => [['timezone' => '-03:00'], 'timezone'],
]);

it('does not create a user when the profile cannot be created', function () {
    // Simula uma falha no meio da transação: o usuário não pode ficar sem perfil.
    Profile::creating(fn () => throw new RuntimeException('profile failure'));

    frontend()->post('/api/v1/auth/register', registration())->assertServerError();

    expect(User::query()->count())->toBe(0);
});

it('rejects registration while already authenticated', function () {
    $session = frontend();
    $session->post('/api/v1/auth/register', registration())->assertCreated();

    $session->post('/api/v1/auth/register', registration(['email' => 'other@example.com', 'nickname' => 'Other']))
        ->assertStatus(409)
        ->assertJsonPath('code', 'already_authenticated');

    expect(User::query()->count())->toBe(1);
});

it('is limited to 3 registrations per minute per IP', function () {
    foreach (range(1, 3) as $i) {
        frontend()->post('/api/v1/auth/register', registration(['email' => "u{$i}@example.com", 'nickname' => "player{$i}"]))
            ->assertCreated();
        Auth::forgetGuards();
    }

    frontend()->post('/api/v1/auth/register', registration(['email' => 'u4@example.com', 'nickname' => 'player4']))
        ->assertTooManyRequests()
        ->assertJsonPath('code', 'too_many_requests');
});

it('only accepts requests from the frontend', function () {
    // Sem Origin do frontend o Sanctum não inicia sessão: o cadastro não pode acontecer.
    $this->postJson('/api/v1/auth/register', registration())
        ->assertForbidden()
        ->assertJsonPath('code', 'frontend_session_required');

    $this->withHeader('Origin', 'https://evil.example')
        ->postJson('/api/v1/auth/register', registration())
        ->assertForbidden();

    expect(User::query()->count())->toBe(0);
});

it('has the full ISO 3166-1 alpha-2 list', function () {
    expect(CountryCodes::ALL)->toHaveCount(249)
        ->and(array_unique(CountryCodes::ALL))->toHaveCount(249)
        ->and(CountryCodes::ALL)->each->toMatch('/^[A-Z]{2}$/');
});
