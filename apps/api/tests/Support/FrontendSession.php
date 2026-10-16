<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Reproduz o navegador no frontend (ADR 0003) dentro de um teste de Feature:
 * guarda os cookies recebidos, reenvia-os nas requisições seguintes, manda Origin do
 * frontend e o header X-XSRF-TOKEN, e esquece o estado em memória entre requisições
 * (sessão e usuário autenticado só podem vir do cookie + Redis).
 *
 * Com `realCsrf()`, roda como APP_ENV=local, onde o Laravel verifica o CSRF de verdade.
 */
final class FrontendSession
{
    public const ORIGIN = 'http://localhost:3000';

    /** @var array<string, string> cookies na forma em que o navegador os guarda (criptografados) */
    private array $cookies = [];

    private bool $sendXsrfHeader = true;

    /** @param  TestCase  $test  o teste atual (`test()` no Pest devolve um proxy para ele) */
    public function __construct(private readonly object $test)
    {
        config(['sanctum.stateful' => ['localhost:3000'], 'session.driver' => 'redis']);
    }

    public function realCsrf(): self
    {
        app()->instance('env', 'local');

        return $this;
    }

    public function withoutXsrfHeader(): self
    {
        $this->sendXsrfHeader = false;

        return $this;
    }

    public function csrfCookie(): TestResponse
    {
        return $this->send('GET', '/sanctum/csrf-cookie');
    }

    /** @param  array<string, mixed>  $data */
    public function post(string $uri, array $data = []): TestResponse
    {
        return $this->send('POST', $uri, $data);
    }

    public function get(string $uri): TestResponse
    {
        return $this->send('GET', $uri);
    }

    public function cookie(string $name): ?string
    {
        return $this->cookies[$name] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    private function send(string $method, string $uri, array $data = []): TestResponse
    {
        self::forgetRequestState();

        $server = [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_REFERER' => self::ORIGIN.'/',
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ];
        if ($this->sendXsrfHeader && isset($this->cookies['XSRF-TOKEN'])) {
            $server['HTTP_X_XSRF_TOKEN'] = $this->cookies['XSRF-TOKEN'];
        }

        // call() direto, com cookies e headers só desta requisição: os métodos with*() do
        // TestCase acumulam estado entre requisições e mascarariam testes de origem e cookie.
        $response = $this->test->call(
            $method,
            $uri,
            cookies: $this->cookies,
            server: $server,
            content: $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR),
        );

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = (string) $cookie->getValue();
            }
        }

        return $response;
    }

    /**
     * Uma nova requisição: nada de sessão ou usuário guardado em memória. Use também antes de
     * requisições montadas à mão no teste, depois de usar esta classe.
     */
    public static function forgetRequestState(): void
    {
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');

        // O router guarda a instância do controller entre requisições do mesmo teste, e os
        // controllers do Fortify recebem o guard no construtor. Em produção cada requisição
        // monta a aplicação do zero.
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
    }
}
