<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\FrontendSession;

/*
| Edição do perfil (etapa 1.5): PATCH /api/v1/me/profile, parcial, só do próprio usuário.
*/

beforeEach(function () {
    config(['sanctum.stateful' => ['localhost:3000']]);
    $this->user = User::factory()->withProfile()->create(['name' => 'Ana']);
    $this->user->profile->update(['nickname' => 'AnaAWP', 'country_code' => 'BR', 'timezone' => 'America/Sao_Paulo']);
});

function patchProfile(User $user, array $data): TestResponse
{
    return test()->actingAs($user)->withHeader('Origin', FrontendSession::ORIGIN)
        ->patchJson('/api/v1/me/profile', $data);
}

it('updates name and profile fields and returns the user', function () {
    patchProfile($this->user, [
        'name' => 'Ana Souza',
        'nickname' => 'AnaRifler',
        'country_code' => 'pt',
        'timezone' => 'Europe/Lisbon',
        'bio' => 'Entry fragger.',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Ana Souza')
        ->assertJsonPath('data.profile.nickname', 'AnaRifler')
        ->assertJsonPath('data.profile.country_code', 'PT')
        ->assertJsonPath('data.profile.timezone', 'Europe/Lisbon')
        ->assertJsonPath('data.profile.bio', 'Entry fragger.');
});

it('changes only the fields that were sent', function () {
    patchProfile($this->user, ['bio' => 'Só a bio.'])->assertOk();

    $profile = $this->user->profile->fresh();
    expect($profile->nickname)->toBe('AnaAWP')
        ->and($profile->country_code)->toBe('BR')
        ->and($profile->bio)->toBe('Só a bio.')
        ->and($this->user->fresh()->name)->toBe('Ana');
});

it('clears optional fields with null or empty values', function () {
    $this->user->profile->update(['bio' => 'Antiga']);

    patchProfile($this->user, ['country_code' => null, 'bio' => ''])->assertOk()
        ->assertJsonPath('data.profile.country_code', null)
        ->assertJsonPath('data.profile.bio', null);
});

it('keeps the own nickname in another case', function () {
    patchProfile($this->user, ['nickname' => 'anaawp'])->assertOk()->assertJsonPath('data.profile.nickname', 'anaawp');
});

it('rejects a nickname used by someone else, in any case', function () {
    Profile::factory()->create(['nickname' => 'Coldzera']);

    patchProfile($this->user, ['nickname' => 'COLDZERA'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.nickname.0', 'Este apelido já está em uso.');
});

it('validates the fields', function (array $data, string $field) {
    patchProfile($this->user, $data)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'blank name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('a', 121)], 'name'],
    'blank nickname' => [['nickname' => ''], 'nickname'],
    'nickname with space' => [['nickname' => 'two words'], 'nickname'],
    'unknown country' => [['country_code' => 'XX'], 'country_code'],
    'invalid timezone' => [['timezone' => 'Mars/Olympus'], 'timezone'],
    'blank timezone' => [['timezone' => ''], 'timezone'],
    'long bio' => [['bio' => str_repeat('a', 501)], 'bio'],
    'nickname as list' => [['nickname' => ['x']], 'nickname'],
]);

it('ignores fields that are not part of the profile', function () {
    patchProfile($this->user, [
        'bio' => 'ok',
        'platform_role' => 'admin',
        'email' => 'hacker@example.com',
        'email_verified_at' => now()->toIso8601String(),
        'user_id' => User::factory()->create()->id,
    ])->assertOk();

    $user = $this->user->fresh();
    expect($user->platform_role)->toBe(PlatformRole::User)
        ->and($user->email)->not->toBe('hacker@example.com')
        ->and($user->profile->user_id)->toBe($user->id);
});

it('only changes the profile of the authenticated user', function () {
    $other = User::factory()->withProfile()->create();
    $before = $other->profile->nickname;

    patchProfile($this->user, ['nickname' => 'Changed1'])->assertOk();

    expect($other->profile->fresh()->nickname)->toBe($before);
});

it('requires authentication', function () {
    $this->withHeader('Origin', FrontendSession::ORIGIN)
        ->patchJson('/api/v1/me/profile', ['bio' => 'x'])
        ->assertUnauthorized();
});

it('forbids an account without a profile', function () {
    patchProfile(User::factory()->create(), ['bio' => 'x'])->assertForbidden()->assertJsonPath('code', 'forbidden');
});

it('requires the CSRF token from the browser', function () {
    $session = (new FrontendSession(test()))->realCsrf();
    $session->csrfCookie();
    $session->post('/api/v1/auth/login', ['email' => $this->user->email, 'password' => 'password'])->assertOk();

    $session->withoutXsrfHeader()->patch('/api/v1/me/profile', ['bio' => 'sem token'])->assertStatus(419);
    $session->withXsrfHeader()->patch('/api/v1/me/profile', ['bio' => 'com token'])->assertOk();

    expect($this->user->profile->fresh()->bio)->toBe('com token');
});

describe('ProfilePolicy', function () {
    it('allows only the owner', function () {
        $other = User::factory()->withProfile()->create();

        expect($this->user->can('update', $this->user->profile))->toBeTrue()
            ->and($this->user->can('update', $other->profile))->toBeFalse()
            ->and(User::factory()->admin()->create()->can('update', $this->user->profile))->toBeFalse();
    });
});
