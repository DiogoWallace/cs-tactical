<?php

namespace App\Modules\System;

use App\Modules\System\Console\CheckInfrastructureCommand;
use App\Modules\System\Console\PrepareE2eDatabaseCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SystemServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CheckInfrastructureCommand::class]);

            // Recria o banco: só existe na stack E2E (compose.e2e.yaml).
            if ($this->app->environment('e2e')) {
                $this->commands([PrepareE2eDatabaseCommand::class]);
            }
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->name('v1.')
            ->group(__DIR__.'/routes.php');
    }
}
