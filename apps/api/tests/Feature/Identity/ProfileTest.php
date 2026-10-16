<?php

use App\Modules\Identity\Models\Profile;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

function insertProfile(array $overrides = []): void
{
    DB::table('profiles')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => User::factory()->create()->id,
        'nickname' => 'Player_'.Str::random(6),
        ...$overrides,
    ]);
}

it('uses a UUIDv7 primary key and a uuid foreign key', function () {
    $profile = Profile::factory()->create();

    expect($profile->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7/');

    $types = DB::table('information_schema.columns')
        ->where('table_name', 'profiles')
        ->whereIn('column_name', ['id', 'user_id'])
        ->pluck('data_type', 'column_name');

    expect($types->all())->toBe(['id' => 'uuid', 'user_id' => 'uuid']);
});

it('creates its owner when built alone', function () {
    $profile = Profile::factory()->create();

    expect($profile->user)->toBeInstanceOf(User::class)
        ->and($profile->user->profile->is($profile))->toBeTrue();
});

it('keeps the nickname as typed, without surrounding spaces', function () {
    $profile = Profile::factory()->create(['nickname' => '  FalleN_br ']);

    expect($profile->fresh()->nickname)->toBe('FalleN_br');
});

it('finds a profile by nickname regardless of case', function () {
    $profile = Profile::factory()->create(['nickname' => 'Coldzera']);

    expect(Profile::query()->whereNicknameIs('COLDZERA')->first()?->id)->toBe($profile->id);
});

it('stores the country code in upper case and defaults the timezone to UTC', function () {
    $profile = User::factory()->create()->profile()->create(['nickname' => 'kscerato', 'country_code' => 'br']);

    expect($profile->fresh()->country_code)->toBe('BR')
        ->and($profile->fresh()->timezone)->toBe('UTC');
});

it('does not allow the owner to be mass assigned', function () {
    (new Profile)->fill(['user_id' => User::factory()->create()->id]);
})->throws(MassAssignmentException::class, 'user_id');

describe('database constraints', function () {
    it('rejects a nickname that differs only in case', function () {
        Profile::factory()->create(['nickname' => 'Gaules']);

        insertProfile(['nickname' => 'gAULES']);
    })->throws(UniqueConstraintViolationException::class, 'profiles_nickname_lower_unique');

    it('rejects a second profile for the same user', function () {
        $profile = Profile::factory()->create();

        insertProfile(['user_id' => $profile->user_id]);
    })->throws(UniqueConstraintViolationException::class, 'profiles_user_id_unique');

    it('rejects a profile for a user that does not exist', function () {
        insertProfile(['user_id' => (string) Str::uuid7()]);
    })->throws(QueryException::class, 'profiles_user_id_foreign');

    it('rejects nicknames outside the allowed format', function (string $nickname) {
        insertProfile(['nickname' => $nickname]);
    })->with([
        'too short' => 'ab',
        'too long' => str_repeat('a', 25),
        'space' => 'two words',
        'accent' => 'jogadôr',
        'symbol' => 'player!',
    ])->throws(QueryException::class);

    it('accepts nicknames at the format limits', function (string $nickname) {
        insertProfile(['nickname' => $nickname]);

        expect(Profile::query()->whereNicknameIs($nickname)->exists())->toBeTrue();
    })->with(['abc', str_repeat('a', 24), 'a.b-c_d']);

    it('rejects a country code that is not two upper case letters', function (string $code) {
        insertProfile(['country_code' => $code]);
    })->with(['br', 'B1'])->throws(QueryException::class, 'profiles_country_code_check');

    it('accepts a profile without country', function () {
        insertProfile(['country_code' => null]);

        expect(Profile::query()->whereNull('country_code')->count())->toBe(1);
    });
});
