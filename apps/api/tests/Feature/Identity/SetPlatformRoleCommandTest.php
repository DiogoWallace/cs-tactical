<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Log;

/*
| php artisan identity:set-role {email} {role} [--force] (etapa 1.6, ADR 0012)
*/

beforeEach(function () {
    // fresh(): como numa requisição real, o usuário vem completo do banco.
    $this->user = User::factory()->create(['email' => 'player@example.com'])->fresh();
});

it('changes the role after confirmation and logs the change', function () {
    Log::spy();

    $this->artisan('identity:set-role', ['email' => 'player@example.com', 'role' => 'editor'])
        ->expectsConfirmation('Mudar o papel de player@example.com de user para editor?', 'yes')
        ->expectsOutput('Papel de player@example.com: user → editor.')
        ->assertSuccessful();

    expect($this->user->fresh()->platform_role)->toBe(PlatformRole::Editor);
    Log::shouldHaveReceived('info')->with('identity.platform_role_changed', [
        'user_id' => $this->user->id,
        'from' => 'user',
        'to' => 'editor',
        'actor' => 'console',
    ])->once();
});

it('accepts --force, any case in the e-mail and the role', function () {
    $this->artisan('identity:set-role', ['email' => ' PLAYER@Example.com ', 'role' => 'ADMIN', '--force' => true])
        ->assertSuccessful();

    expect($this->user->fresh()->platform_role)->toBe(PlatformRole::Admin);
});

it('does nothing when the answer is no', function () {
    $this->artisan('identity:set-role', ['email' => 'player@example.com', 'role' => 'admin'])
        ->expectsConfirmation('Mudar o papel de player@example.com de user para admin?', 'no')
        ->expectsOutput('Cancelado. Nada mudou.')
        ->assertFailed();

    expect($this->user->fresh()->platform_role)->toBe(PlatformRole::User);
});

it('rejects an unknown role', function () {
    $this->artisan('identity:set-role', ['email' => 'player@example.com', 'role' => 'root', '--force' => true])
        ->expectsOutput('Papel inválido. Use um destes: user, editor, admin.')
        ->assertExitCode(2);

    expect($this->user->fresh()->platform_role)->toBe(PlatformRole::User);
});

it('rejects an unknown e-mail', function () {
    $this->artisan('identity:set-role', ['email' => 'nobody@example.com', 'role' => 'admin', '--force' => true])
        ->expectsOutput('Nenhuma conta com esse e-mail.')
        ->assertFailed();
});

it('reports when the role is already set', function () {
    Log::spy();

    $this->artisan('identity:set-role', ['email' => 'player@example.com', 'role' => 'user'])
        ->expectsOutput('A conta já tem o papel user. Nada mudou.')
        ->assertSuccessful();

    Log::shouldNotHaveReceived('info');
});

it('refuses to demote the last admin', function () {
    $this->user->forceFill(['platform_role' => PlatformRole::Admin])->save();

    $this->artisan('identity:set-role', ['email' => 'player@example.com', 'role' => 'editor', '--force' => true])
        ->expectsOutput('Não é possível rebaixar o último admin da plataforma.')
        ->assertFailed();

    expect($this->user->fresh()->platform_role)->toBe(PlatformRole::Admin);
});

it('demotes an admin when another admin remains', function () {
    $this->user->forceFill(['platform_role' => PlatformRole::Admin])->save();
    User::factory()->admin()->create();

    $this->artisan('identity:set-role', ['email' => 'player@example.com', 'role' => 'user', '--force' => true])
        ->assertSuccessful();

    expect($this->user->fresh()->platform_role)->toBe(PlatformRole::User);
});

it('takes effect on the next request of the user', function () {
    $this->actingAs($this->user)->withHeader('Origin', 'http://localhost:3000')
        ->getJson('/api/v1/me')->assertJsonPath('data.abilities', []);

    $this->artisan('identity:set-role', ['email' => 'player@example.com', 'role' => 'editor', '--force' => true]);

    $this->actingAs($this->user->fresh())->withHeader('Origin', 'http://localhost:3000')
        ->getJson('/api/v1/me')->assertJsonPath('data.abilities', ['manage-academy-content']);
});
