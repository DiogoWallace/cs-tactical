<?php

use Laravel\Fortify\Features;

/*
| Laravel Fortify em modo headless (ADR 0010).
|
| As rotas do pacote estão desligadas (Fortify::ignoreRoutes() no IdentityServiceProvider):
| o módulo Identity declara apenas os endpoints em uso, sob /api/v1/auth, usando os
| controllers do Fortify. Assim nenhum endpoint fica exposto sem ter sido decidido
| (o pacote registraria sempre, por exemplo, /user/confirm-password).
|
| `prefix`, `domain`, `middleware`, `views` e `home` só teriam efeito nas rotas e telas do
| pacote; ficam com valores neutros.
*/

return [

    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    // O e-mail é comparado em minúsculas, como é gravado (User::normalizeEmail).
    'lowercase_usernames' => true,

    'home' => '/',

    'prefix' => 'api/v1/auth',

    'domain' => null,

    'middleware' => ['api'],

    // Limitadores definidos no IdentityServiceProvider. Com `login` definido, o Fortify
    // deixa a contagem para o middleware throttle da rota.
    'limiters' => [
        'login' => 'login',
    ],

    'views' => false,

    // Recursos ligados conforme as etapas da Fase 1. Login e logout não são "features":
    // existem sempre. Recuperação de senha e verificação de e-mail entram na etapa 1.4;
    // alteração de senha na 1.5. Autenticação em dois fatores e passkeys: fora da Fase 1.
    'features' => [
        Features::registration(),
    ],

];
