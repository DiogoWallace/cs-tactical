<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('reports ok when database and redis are reachable', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'ok')
        ->assertJsonStructure([
            'status',
            'timestamp',
            'checks' => [
                'database' => ['status', 'latency_ms'],
                'redis' => ['status', 'latency_ms'],
            ],
        ]);
});

it('reports degraded with 503 when the database is unreachable', function () {
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database.status', 'fail')
        ->assertJsonPath('checks.redis.status', 'ok');
});

it('reports degraded with 503 when redis is unreachable', function () {
    config(['database.redis.default.port' => 1]);
    Redis::purge('default');

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertJsonPath('status', 'degraded')
        ->assertJsonPath('checks.database.status', 'ok')
        ->assertJsonPath('checks.redis.status', 'fail');
});

it('hides dependency details when they are not exposed', function () {
    config(['health.expose_details' => false]);

    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonMissingPath('checks')
        ->assertJsonPath('status', 'ok');
});

it('never leaks error messages in the response', function () {
    config(['database.connections.pgsql.port' => 1]);
    DB::purge('pgsql');

    $response = $this->getJson('/api/v1/health');

    expect($response->getContent())
        ->not->toContain('SQLSTATE')
        ->not->toContain('Connection refused');
});

it('keeps the framework liveness route available', function () {
    $this->get('/up')->assertOk();
});

it('runs against the dedicated test database', function () {
    expect(app()->environment())->toBe('testing')
        ->and(DB::connection()->getDatabaseName())->toBe('cs_tactical_test');
});
