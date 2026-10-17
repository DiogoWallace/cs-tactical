<?php

/*
| system:check-production-config (bloqueios P3 e P4, docs/deploy/bloqueios-de-producao.md).
*/

function productionConfig(array $overrides = []): void
{
    config([
        'app.env' => 'production',
        'app.debug' => false,
        'session.secure' => true,
        'session.same_site' => 'lax',
        'health.expose_details' => false,
        'app.url' => 'https://api.cstactical.example',
        'app.frontend_url' => 'https://cstactical.example',
        'sanctum.stateful' => ['cstactical.example'],
        'trustedproxy.proxies' => ['10.0.0.5'],
        'logging.default' => 'stack',
        'logging.channels.stack.level' => 'info',
        'mail.default' => 'smtp',
        ...$overrides,
    ]);
}

it('passes with a safe production configuration', function () {
    productionConfig();

    $this->artisan('system:check-production-config')
        ->doesntExpectOutputToContain('FALHA')
        ->expectsOutputToContain('pronta para produção')
        ->assertSuccessful();
});

it('fails on each unsafe item', function (array $overrides, string $item) {
    productionConfig($overrides);

    // A tabela lista todos os itens; só o inseguro pode estar marcado como FALHA.
    $this->artisan('system:check-production-config')
        ->expectsOutputToContain("| FALHA | {$item}")
        ->expectsOutputToContain('1 item(ns) impedem o deploy')
        ->assertFailed();
})->with([
    'not production' => [['app.env' => 'local'], 'APP_ENV=production'],
    'debug on' => [['app.debug' => true], 'APP_DEBUG=false'],
    'cookie without Secure' => [['session.secure' => null], 'SESSION_SECURE_COOKIE=true'],
    'SameSite none' => [['session.same_site' => 'none'], 'SESSION_SAME_SITE'],
    'health details exposed' => [['health.expose_details' => true], 'HEALTH_EXPOSE_DETAILS=false'],
    'http app url' => [['app.url' => 'http://api.cstactical.example'], 'APP_URL em https'],
    'http frontend url' => [['app.frontend_url' => 'http://cstactical.example'], 'FRONTEND_URL em https'],
    'localhost stateful domain' => [['sanctum.stateful' => ['cstactical.example', 'localhost:3000']], 'SANCTUM_STATEFUL_DOMAINS'],
    'no stateful domain' => [['sanctum.stateful' => []], 'SANCTUM_STATEFUL_DOMAINS'],
    'no trusted proxies' => [['trustedproxy.proxies' => null], 'TRUSTED_PROXIES definido'],
    'broad proxy range' => [['trustedproxy.proxies' => ['10.0.0.0/8']], 'TRUSTED_PROXIES sem faixas'],
    'debug log level' => [['logging.channels.stack.level' => 'debug'], 'LOG_LEVEL'],
    'log mailer' => [['mail.default' => 'log'], 'MAIL_MAILER'],
]);

it('only reads the configuration', function () {
    productionConfig(['app.debug' => true]);

    $this->artisan('system:check-production-config')->assertFailed();

    expect(config('app.debug'))->toBeTrue();
});
