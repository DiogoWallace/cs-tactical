<?php

namespace App\Modules\System\Console;

use Illuminate\Console\Command;

/**
 * php artisan system:check-production-config
 *
 * Confere, só lendo a configuração, os itens de aplicação dos bloqueios P3 e P4
 * (docs/deploy/bloqueios-de-producao.md). Falha (código 1) se algum não estiver seguro; o
 * pipeline de deploy deve rodá-lo no ambiente de produção antes de liberar o tráfego.
 *
 * Não substitui a verificação externa de P1 e P2 (borda e rede), que depende da infraestrutura.
 */
class CheckProductionConfigCommand extends Command
{
    protected $signature = 'system:check-production-config';

    protected $description = 'Verifica se a configuração atual é segura para produção (bloqueios P3 e P4)';

    public function handle(): int
    {
        $checks = $this->checks();

        $this->table(
            ['', 'Item', 'Valor atual'],
            array_map(fn (array $check) => [$check[1] ? 'ok' : 'FALHA', $check[0], $check[2]], $checks),
        );

        $failures = count(array_filter($checks, fn (array $check) => ! $check[1]));
        if ($failures > 0) {
            $this->error("{$failures} item(ns) impedem o deploy em produção.");

            return self::FAILURE;
        }

        $this->info('Configuração de aplicação pronta para produção (P3 e P4). P1 e P2 exigem verificação externa.');

        return self::SUCCESS;
    }

    /** @return list<array{string, bool, string}> [descrição, passou, valor atual] */
    private function checks(): array
    {
        $env = (string) config('app.env');
        $debug = (bool) config('app.debug');
        $secure = config('session.secure');
        $sameSite = (string) config('session.same_site');
        $exposeHealth = (bool) config('health.expose_details');
        $logLevel = (string) config('logging.channels.'.config('logging.default').'.level', 'debug');
        $mailer = (string) config('mail.default');
        $appUrl = (string) config('app.url');
        $frontendUrl = (string) config('app.frontend_url');
        $steamEndpoint = (string) config('steam.openid_endpoint');

        /** @var list<string> $stateful */
        $stateful = (array) config('sanctum.stateful', []);
        $localStateful = array_values(array_filter(
            $stateful,
            fn (string $domain): bool => preg_match('/^(localhost|127\.|\[::1\]|0\.0\.0\.0)/', $domain) === 1,
        ));

        /** @var list<string>|null $proxies */
        $proxies = config('trustedproxy.proxies');
        $broadProxies = array_values(array_filter(
            (array) $proxies,
            fn (string $proxy): bool => preg_match('#/(\d+)$#', $proxy, $m) === 1 && (int) $m[1] < 24,
        ));

        return [
            ['APP_ENV=production', $env === 'production', $env],
            ['APP_DEBUG=false (erros sem exception/trace)', ! $debug, $debug ? 'true' : 'false'],
            ['SESSION_SECURE_COOKIE=true (cookies só em HTTPS)', $secure === true, var_export($secure, true)],
            ['SESSION_SAME_SITE lax ou strict', in_array($sameSite, ['lax', 'strict'], true), $sameSite],
            ['HEALTH_EXPOSE_DETAILS=false', ! $exposeHealth, $exposeHealth ? 'true' : 'false'],
            ['APP_URL em https', str_starts_with($appUrl, 'https://'), $appUrl],
            ['FRONTEND_URL em https (links dos e-mails)', str_starts_with($frontendUrl, 'https://'), $frontendUrl],
            [
                'SANCTUM_STATEFUL_DOMAINS só com o domínio público',
                $stateful !== [] && $localStateful === [],
                $stateful === [] ? '(vazio)' : implode(',', $stateful),
            ],
            [
                'TRUSTED_PROXIES definido (P3)',
                is_array($proxies) && $proxies !== [],
                is_array($proxies) ? implode(',', $proxies) : '(vazio)',
            ],
            [
                'TRUSTED_PROXIES sem faixas maiores que /24 (P3)',
                $broadProxies === [],
                $broadProxies === [] ? 'ok' : implode(',', $broadProxies),
            ],
            ['LOG_LEVEL diferente de debug', $logLevel !== 'debug', $logLevel],
            ['MAIL_MAILER envia e-mails de verdade', ! in_array($mailer, ['log', 'array'], true), $mailer],
            // Um endpoint trocado aceitaria asserções de um provedor falso (etapa M2).
            [
                'Vínculo Steam no endpoint oficial (https://steamcommunity.com)',
                $steamEndpoint === config('steam.official_endpoint'),
                $steamEndpoint,
            ],
        ];
    }
}
