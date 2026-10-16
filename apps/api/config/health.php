<?php

return [

    /*
    | Exibe o resultado de cada dependência (banco, Redis) em /api/v1/health.
    | Ligado por padrão apenas em ambiente local.
    */
    'expose_details' => (bool) env('HEALTH_EXPOSE_DETAILS', env('APP_ENV') === 'local'),

];
