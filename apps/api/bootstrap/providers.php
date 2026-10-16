<?php

use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\System\SystemServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Módulos de negócio (app/Modules). Cada módulo registra suas rotas e policies.
    SystemServiceProvider::class,
    IdentityServiceProvider::class,
];
