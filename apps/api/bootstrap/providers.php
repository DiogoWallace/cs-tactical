<?php

use App\Modules\Academy\AcademyServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Learning\LearningServiceProvider;
use App\Modules\System\SystemServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,

    // Módulos de negócio (app/Modules). Cada módulo registra suas rotas e policies.
    SystemServiceProvider::class,
    IdentityServiceProvider::class,
    AcademyServiceProvider::class,
    LearningServiceProvider::class,
];
