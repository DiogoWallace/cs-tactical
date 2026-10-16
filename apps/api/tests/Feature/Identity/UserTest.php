<?php

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const UUID_V7 = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

describe('identifiers', function () {
    it('uses UUIDv7 primary keys stored as native uuid', function () {
        $user = User::factory()->create();

        expect($user->id)->toMatch(UUID_V7)
            ->and($user->getIncrementing())->toBeFalse()
            ->and($user->getKeyType())->toBe('string');

        $type = DB::scalar("select data_type from information_schema.columns where table_name = 'users' and column_name = 'id'");
        expect($type)->toBe('uuid');
    });

    it('generates time-ordered identifiers', function () {
        $ids = User::factory()->count(5)->create()->pluck('id')->all();

        $sorted = $ids;
        sort($sorted);

        expect($ids)->toBe($sorted);
    });
});

describe('attributes', function () {
    it('hashes the password and hides secrets from serialization', function () {
        $user = User::factory()->create(['password' => 'secret-password']);

        expect(Hash::check('secret-password', $user->password))->toBeTrue()
            ->and($user->password)->not->toBe('secret-password')
            ->and($user->toArray())->not->toHaveKeys(['password', 'remember_token']);
    });

    it('stores the e-mail trimmed and in lower case', function () {
        $user = User::factory()->create(['email' => '  Player.One@Example.COM ']);

        expect($user->fresh()->email)->toBe('player.one@example.com');
    });

    it('finds a user by e-mail regardless of case', function () {
        $user = User::factory()->create(['email' => 'player@example.com']);

        expect(User::query()->whereEmailIs(' PLAYER@example.com ')->first()?->id)->toBe($user->id);
    });

    it('casts the platform role to the enum and defaults to user', function () {
        $user = User::create(['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'secret-password']);

        expect($user->platform_role)->toBe(PlatformRole::User)
            ->and($user->fresh()->platform_role)->toBe(PlatformRole::User)
            ->and($user->hasPlatformRole(PlatformRole::User))->toBeTrue()
            ->and(User::factory()->admin()->create()->platform_role)->toBe(PlatformRole::Admin)
            ->and(User::factory()->editor()->create()->platform_role)->toBe(PlatformRole::Editor);
    });

    it('starts unverified when created without verification', function () {
        expect(User::factory()->unverified()->create()->email_verified_at)->toBeNull()
            ->and(User::factory()->create()->email_verified_at)->not->toBeNull();
    });
});

describe('mass assignment', function () {
    it('does not allow the platform role to be mass assigned', function () {
        User::create([
            'name' => 'Mallory',
            'email' => 'mallory@example.com',
            'password' => 'secret-password',
            'platform_role' => 'admin',
        ]);
    })->throws(MassAssignmentException::class, 'platform_role');

    it('does not allow the verification date to be mass assigned', function () {
        (new User)->fill(['email_verified_at' => now()]);
    })->throws(MassAssignmentException::class, 'email_verified_at');

    it('does not allow the id to be mass assigned', function () {
        (new User)->fill(['id' => '0199f1c2-0000-7000-8000-000000000000']);
    })->throws(MassAssignmentException::class, 'id');

    it('drops guarded attributes without applying them when strict mode is off', function () {
        // Produção: o modo estrito fica desligado (AppServiceProvider) e o atributo é descartado.
        // O próximo teste sobe a aplicação de novo e religa o modo estrito.
        Model::preventSilentlyDiscardingAttributes(false);

        $user = (new User)->fill(['name' => 'Mallory', 'platform_role' => 'admin', 'email_verified_at' => now()]);

        expect($user->platform_role)->toBe(PlatformRole::User)
            ->and($user->email_verified_at)->toBeNull()
            ->and($user->name)->toBe('Mallory');
    });
});

describe('database constraints', function () {
    it('rejects an e-mail that differs only in case', function () {
        User::factory()->create(['email' => 'dup@example.com']);

        // Inserção direta, sem a normalização do model: a garantia é do banco.
        DB::table('users')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Dup',
            'email' => 'DUP@Example.com',
            'password' => 'x',
        ]);
    })->throws(UniqueConstraintViolationException::class, 'users_email_lower_unique');

    it('rejects an unknown platform role', function () {
        $user = User::factory()->create();

        DB::table('users')->where('id', $user->id)->update(['platform_role' => 'root']);
    })->throws(QueryException::class, 'users_platform_role_check');

    it('rejects a blank e-mail or name', function (string $column) {
        $user = User::factory()->create();

        DB::table('users')->where('id', $user->id)->update([$column => '   ']);
    })->with(['email', 'name'])->throws(QueryException::class, '_not_blank_check');
});

describe('authentication configuration', function () {
    it('uses the Identity user model in the default guard', function () {
        expect(config('auth.providers.users.model'))->toBe(User::class)
            ->and(Auth::guard('web')->getProvider()->getModel())->toBe(User::class);
    });

    it('retrieves users by credentials through the provider', function () {
        $user = User::factory()->create(['email' => 'login@example.com', 'password' => 'secret-password']);

        $provider = Auth::guard('web')->getProvider();
        $found = $provider->retrieveByCredentials(['email' => 'login@example.com']);

        expect($found?->getAuthIdentifier())->toBe($user->id)
            ->and($provider->validateCredentials($found, ['password' => 'secret-password']))->toBeTrue();
    });
});

describe('profile relationship', function () {
    it('has one profile', function () {
        $user = User::factory()->withProfile()->create();

        expect($user->profile)->toBeInstanceOf(Profile::class)
            ->and($user->profile->user->is($user))->toBeTrue();
    });

    it('has no profile unless asked for one', function () {
        expect(User::factory()->create()->profile)->toBeNull();
    });

    it('deletes the profile together with the user', function () {
        $user = User::factory()->withProfile()->create();
        $profileId = $user->profile->id;

        $user->delete();

        expect(Profile::query()->whereKey($profileId)->exists())->toBeFalse();
    });
});
