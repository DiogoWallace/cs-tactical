<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fora de produção, o Eloquent falha em vez de esconder erros: lazy loading (N+1),
        // atributo não preenchível passado em massa (ex.: platform_role) e atributo inexistente.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
