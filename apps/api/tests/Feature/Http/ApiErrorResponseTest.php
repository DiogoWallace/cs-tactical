<?php

/*
| Formato padronizado das respostas de erro da API (docs/api/convencoes.md#erros).
*/

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/__errors')->group(function () {
        Route::get('/auth', fn () => ['ok' => true])->middleware('auth:sanctum');
        Route::get('/forbidden', fn () => throw new AuthorizationException);
        Route::get('/forbidden-custom', fn () => throw new AuthorizationException('Only editors can publish.'));
        Route::get('/model', fn () => throw (new ModelNotFoundException)->setModel('App\Modules\Secret\Models\Hidden', ['0199']));
        Route::post('/post-only', fn () => ['ok' => true]);
        Route::get('/csrf', fn () => throw new TokenMismatchException);
        Route::post('/validate', fn (Request $request) => $request->validate(['email' => ['required', 'email']]));
        Route::get('/throttled', fn () => ['ok' => true])->middleware('throttle:1,1');
        Route::get('/boom', fn () => throw new RuntimeException('database password is hunter2'));
        Route::get('/unavailable', fn () => abort(503));
        Route::get('/business', fn () => throw new HttpResponseException(response()->json([
            'message' => 'The team is full.',
            'code' => 'team_member_limit_reached',
        ], 409)));
    });
});

it('formats 401 for unauthenticated requests', function () {
    $this->getJson('/api/v1/__errors/auth')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.', 'code' => 'unauthenticated']);
});

it('formats 403 and keeps the authorization message', function () {
    $this->getJson('/api/v1/__errors/forbidden')
        ->assertForbidden()
        ->assertExactJson(['message' => 'This action is unauthorized.', 'code' => 'forbidden']);

    $this->getJson('/api/v1/__errors/forbidden-custom')
        ->assertExactJson(['message' => 'Only editors can publish.', 'code' => 'forbidden']);
});

it('formats 404 for unknown routes without leaking the path', function () {
    config(['app.debug' => true]);

    $this->getJson('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
});

it('formats 404 for missing models without leaking class names', function () {
    $response = $this->getJson('/api/v1/__errors/model')->assertNotFound();

    $response->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
    expect($response->getContent())->not->toContain('Hidden')->not->toContain('Modules');
});

it('formats 404 for any /api path even without the Accept header', function () {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertExactJson(['message' => 'Not found.', 'code' => 'not_found']);
});

it('formats 405', function () {
    $this->getJson('/api/v1/__errors/post-only')
        ->assertStatus(405)
        ->assertExactJson(['message' => 'Method not allowed.', 'code' => 'method_not_allowed']);
});

it('formats 419', function () {
    $this->getJson('/api/v1/__errors/csrf')
        ->assertStatus(419)
        ->assertExactJson(['message' => 'CSRF token mismatch.', 'code' => 'csrf_token_mismatch']);
});

it('formats 422 and keeps the field errors', function () {
    $this->postJson('/api/v1/__errors/validate', ['email' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['message', 'code', 'errors' => ['email']]);
});

it('formats 429 and keeps the Retry-After header', function () {
    $this->getJson('/api/v1/__errors/throttled')->assertOk();

    $this->getJson('/api/v1/__errors/throttled')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertExactJson(['message' => 'Too Many Attempts.', 'code' => 'too_many_requests']);
});

it('hides internal error details when debug is off', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/__errors/boom')
        ->assertServerError()
        ->assertExactJson(['message' => 'Server Error', 'code' => 'server_error']);

    expect($response->getContent())->not->toContain('hunter2');
});

it('keeps debug details for 5xx errors when debug is on', function () {
    config(['app.debug' => true]);

    $this->getJson('/api/v1/__errors/boom')
        ->assertServerError()
        ->assertJsonPath('code', 'server_error')
        ->assertJsonPath('exception', RuntimeException::class)
        ->assertJsonStructure(['message', 'code', 'exception', 'file', 'line', 'trace']);
});

it('never includes debug details in 4xx errors', function () {
    config(['app.debug' => true]);

    $this->getJson('/api/v1/__errors/forbidden')
        ->assertJsonMissingPath('exception')
        ->assertJsonMissingPath('trace');
});

it('formats 503', function () {
    config(['app.debug' => false]);

    $this->getJson('/api/v1/__errors/unavailable')
        ->assertServiceUnavailable()
        ->assertExactJson(['message' => 'Service Unavailable', 'code' => 'service_unavailable']);
});

it('keeps a business error code set by the response', function () {
    $this->getJson('/api/v1/__errors/business')
        ->assertConflict()
        ->assertExactJson(['message' => 'The team is full.', 'code' => 'team_member_limit_reached']);
});

it('does not change the health check response', function () {
    // O 503 do health check é uma resposta normal da Action, não uma exceção.
    $this->getJson('/api/v1/health')->assertOk()->assertJsonMissingPath('code');
});
