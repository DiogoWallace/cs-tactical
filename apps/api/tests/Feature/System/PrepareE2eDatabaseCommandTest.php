<?php

use App\Modules\Identity\Models\User;
use App\Modules\System\Console\PrepareE2eDatabaseCommand;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// O caminho de sucesso (recriar cs_tactical_e2e) é exercido pela stack E2E (`make e2e`).

it('is not registered outside the e2e environment', function () {
    expect(Artisan::all())->not->toHaveKey('e2e:prepare');
});

it('refuses to run outside the e2e environment and keeps the data', function () {
    $user = User::factory()->create();
    $this->app->make(Kernel::class)->registerCommand($this->app->make(PrepareE2eDatabaseCommand::class));

    $this->artisan('e2e:prepare')
        ->expectsOutputToContain('APP_ENV precisa ser e2e')
        ->assertFailed();

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

it('refuses to seed the archived-course history outside the e2e environment', function () {
    $user = User::factory()->create();
    $this->app->make(Kernel::class)->registerCommand($this->app->make(PrepareE2eDatabaseCommand::class));

    $this->artisan('e2e:prepare', ['--seed-history' => true])
        ->expectsOutputToContain('APP_ENV precisa ser e2e')
        ->assertFailed();

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue()
        ->and(DB::table('enrollments')->count())->toBe(0);
});

it('refuses to run against a database other than cs_tactical_e2e', function () {
    $user = User::factory()->create();
    $this->app['env'] = 'e2e';
    $this->app->make(Kernel::class)->registerCommand($this->app->make(PrepareE2eDatabaseCommand::class));

    $this->artisan('e2e:prepare')
        ->expectsOutputToContain('DB_DATABASE precisa ser cs_tactical_e2e')
        ->assertFailed();

    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});
