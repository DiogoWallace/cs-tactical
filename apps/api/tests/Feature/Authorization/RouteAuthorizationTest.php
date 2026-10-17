<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/*
| Arquitetura (ADR 0012): toda rota da API exige autenticação, exceto as públicas listadas
| aqui de propósito. Uma rota nova sem `auth:sanctum` faz este teste falhar até ser decidida.
*/

/** Rotas sem sessão, por desenho. Cada uma tem seu motivo. */
const PUBLIC_API_ROUTES = [
    'v1.health' => 'readiness da infraestrutura',
    'v1.auth.register' => 'cadastro',
    'v1.auth.login' => 'login',
    'v1.auth.password.email' => 'esqueci minha senha',
    'v1.auth.password.update' => 'redefinição com token do e-mail',
    'sanctum.csrf-cookie' => 'emite o cookie CSRF',
    // Catálogo da Academy (ADR 0015, item 6): leitura pública, só de conteúdo público.
    'v1.academy.courses.index' => 'catálogo público de cursos',
    'v1.academy.courses.show' => 'curso público',
    'v1.academy.lessons.show' => 'aula pública',
    'v1.academy.media.show' => 'imagens de conteúdo público (ADR 0019)',
];

/** @return list<RouteDefinition> */
function apiRoutes(): array
{
    return array_values(array_filter(
        Route::getRoutes()->getRoutes(),
        fn (RouteDefinition $route) => str_starts_with($route->uri(), 'api/') || str_starts_with($route->uri(), 'sanctum/'),
    ));
}

it('requires authentication on every API route that is not explicitly public', function () {
    $unprotected = [];

    foreach (apiRoutes() as $route) {
        $middleware = $route->gatherMiddleware();
        $authenticated = collect($middleware)->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth:'));

        if (! $authenticated && ! array_key_exists((string) $route->getName(), PUBLIC_API_ROUTES)) {
            $unprotected[] = implode('|', $route->methods()).' '.$route->uri().' ('.($route->getName() ?? 'sem nome').')';
        }
    }

    expect($unprotected)->toBe([]);
});

it('keeps the public list in sync with the real routes', function () {
    $names = array_map(fn (RouteDefinition $route) => $route->getName(), apiRoutes());

    foreach (array_keys(PUBLIC_API_ROUTES) as $name) {
        expect($names)->toContain($name);
    }
});

it('rate limits every public route that changes state', function () {
    $missing = [];

    foreach (apiRoutes() as $route) {
        $public = array_key_exists((string) $route->getName(), PUBLIC_API_ROUTES);
        $writes = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
        $throttled = collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:'));

        if ($public && $writes && ! $throttled) {
            $missing[] = $route->uri();
        }
    }

    expect($missing)->toBe([]);
});
