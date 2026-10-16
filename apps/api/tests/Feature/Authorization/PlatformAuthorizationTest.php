<?php

use App\Modules\Identity\Enums\PlatformAbility;
use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\Support\FrontendSession;

/*
| Autorização por papel de plataforma (etapa 1.6, ADR 0012).
| A matriz abaixo é a especificação: qualquer mudança nela é uma decisão de produto.
*/

const PLATFORM_MATRIX = [
    'user' => ['manage-academy-content' => false, 'administer-platform' => false],
    'editor' => ['manage-academy-content' => true, 'administer-platform' => false],
    'admin' => ['manage-academy-content' => true, 'administer-platform' => true],
];

function userWithRole(string $role): User
{
    $user = User::factory()->withProfile()->create();
    $user->forceFill(['platform_role' => $role])->save();

    return $user->fresh();
}

/** @return array<string, array{string, string, bool}> */
function platformMatrix(): array
{
    $cases = [];
    foreach (PLATFORM_MATRIX as $role => $abilities) {
        foreach ($abilities as $ability => $allowed) {
            $cases["{$role} → {$ability}"] = [$role, $ability, $allowed];
        }
    }

    return $cases;
}

describe('gates', function () {
    it('follow the capability matrix', function (string $role, string $ability, bool $allowed) {
        expect(Gate::forUser(userWithRole($role))->allows($ability))->toBe($allowed);
    })->with(platformMatrix());

    it('deny visitors', function (string $ability) {
        expect(Gate::allows($ability))->toBeFalse();
    })->with(array_map(fn (PlatformAbility $a) => $a->value, PlatformAbility::cases()));

    it('exist for every ability and nothing else is granted by role', function () {
        foreach (PlatformAbility::cases() as $ability) {
            expect(Gate::has($ability->value))->toBeTrue();
        }

        expect(array_keys(PLATFORM_MATRIX))->toBe(PlatformRole::values());
        foreach (PlatformRole::cases() as $role) {
            $granted = array_map(fn (PlatformAbility $a) => $a->value, $role->abilities());
            $expected = array_keys(array_filter(PLATFORM_MATRIX[$role->value]));
            expect($granted)->toBe($expected);
        }
    });

    it('apply the new role on the next check', function () {
        $user = userWithRole('user');
        expect(Gate::forUser($user)->allows('manage-academy-content'))->toBeFalse();

        $user->forceFill(['platform_role' => PlatformRole::Editor])->save();

        expect(Gate::forUser($user->fresh())->allows('manage-academy-content'))->toBeTrue();
    });
});

describe('http', function () {
    beforeEach(function () {
        Route::middleware(['api', 'auth:sanctum', 'can:manage-academy-content'])
            ->get('/api/v1/__probe/academy', fn () => ['ok' => true]);
        Route::middleware(['api', 'auth:sanctum', 'can:administer-platform'])
            ->get('/api/v1/__probe/admin', fn () => ['ok' => true]);
    });

    it('protects routes with the can middleware', function (string $role, string $ability, bool $allowed) {
        $uri = $ability === 'manage-academy-content' ? '/api/v1/__probe/academy' : '/api/v1/__probe/admin';

        $response = $this->actingAs(userWithRole($role))->withHeader('Origin', FrontendSession::ORIGIN)->getJson($uri);

        $allowed
            ? $response->assertOk()
            : $response->assertForbidden()->assertJsonPath('code', 'forbidden');
    })->with(platformMatrix());

    it('answers 401 to visitors', function () {
        $this->getJson('/api/v1/__probe/academy')->assertUnauthorized();
        $this->getJson('/api/v1/__probe/admin')->assertUnauthorized();
    });
});

describe('/me abilities', function () {
    it('lists the effective abilities of each role', function (string $role) {
        $expected = array_keys(array_filter(PLATFORM_MATRIX[$role]));

        $this->actingAs(userWithRole($role))->withHeader('Origin', FrontendSession::ORIGIN)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.platform_role', $role)
            ->assertJsonPath('data.abilities', $expected);
    })->with(array_keys(PLATFORM_MATRIX));

    it('reflects the gates, not the role name', function () {
        // Se um Gate mudar de regra, /me acompanha: a lista vem dos Gates, não de uma cópia.
        Gate::define('manage-academy-content', fn () => false);

        $this->actingAs(userWithRole('admin'))->withHeader('Origin', FrontendSession::ORIGIN)
            ->getJson('/api/v1/me')
            ->assertJsonPath('data.abilities', ['administer-platform']);
    });
});

describe('platform roles and other accounts', function () {
    it('never let any role edit another account', function (string $role) {
        $actor = userWithRole($role);
        $other = userWithRole('user');

        expect($actor->can('update', $other->profile))->toBeFalse()
            ->and($actor->can('updateCredentials', $other))->toBeFalse()
            ->and($actor->can('update', $actor->profile))->toBeTrue()
            ->and($actor->can('updateCredentials', $actor))->toBeTrue();
    })->with(array_keys(PLATFORM_MATRIX));
});
