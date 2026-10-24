<?php

/*
| Etapa 2.7b, achado A1: nenhuma resposta da API pode ficar guardada, nem no navegador
| (`no-store`); antes ela saía com o padrão do Laravel, `no-cache, private`, que impede caches
| compartilhados mas deixa o navegador gravar o JSON (progresso, tentativas, dados da conta).
*/

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/__cache')->group(function () {
        Route::get('/private', fn () => ['ok' => true])->middleware('auth:sanctum');
        Route::get('/public', fn () => ['ok' => true]);
        Route::get('/missing', fn () => abort(404));
    });
});

function expectNoStore(TestResponse $response): void
{
    $directives = array_map('trim', explode(',', (string) $response->headers->get('Cache-Control')));

    expect($directives)->toContain('no-store')
        ->and($directives)->toContain('private')
        ->and($directives)->not->toContain('no-cache')
        ->and($response->headers->has('ETag'))->toBeFalse();
}

it('marks authenticated responses as no-store', function () {
    $user = User::factory()->withProfile()->create()->fresh();

    expectNoStore($this->actingAs($user)->getJson('/api/v1/__cache/private')->assertOk());
    expectNoStore($this->actingAs($user)->getJson('/api/v1/me')->assertOk());
});

it('marks public responses and errors as no-store too', function () {
    expectNoStore($this->getJson('/api/v1/__cache/public')->assertOk());
    expectNoStore($this->getJson('/api/v1/__cache/private')->assertUnauthorized());
    expectNoStore($this->getJson('/api/v1/__cache/missing')->assertNotFound());
    expectNoStore($this->getJson('/api/v1/__cache/nao-existe')->assertNotFound());
    expectNoStore($this->getJson('/api/v1/academy/courses')->assertOk());
});
