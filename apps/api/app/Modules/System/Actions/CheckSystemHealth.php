<?php

namespace App\Modules\System\Actions;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Verifica as dependências externas da API (readiness).
 * Diferente de /up, que só indica que o processo PHP está vivo.
 */
class CheckSystemHealth
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAIL = 'fail';

    /**
     * @return array{healthy: bool, checks: array<string, array{status: string, latency_ms: float}>}
     */
    public function handle(): array
    {
        $checks = [
            'database' => $this->measure('database', fn () => DB::connection()->select('select 1')),
            'redis' => $this->measure('redis', fn () => Redis::connection()->ping()),
        ];

        $healthy = collect($checks)->every(fn (array $check) => $check['status'] === self::STATUS_OK);

        return ['healthy' => $healthy, 'checks' => $checks];
    }

    /**
     * @return array{status: string, latency_ms: float}
     */
    private function measure(string $name, Closure $probe): array
    {
        $start = hrtime(true);

        try {
            $probe();
            $status = self::STATUS_OK;
        } catch (Throwable $e) {
            // O detalhe do erro vai para o log, nunca para a resposta HTTP.
            Log::warning('Health check failed', ['check' => $name, 'error' => $e->getMessage()]);
            $status = self::STATUS_FAIL;
        }

        return [
            'status' => $status,
            'latency_ms' => round((hrtime(true) - $start) / 1_000_000, 2),
        ];
    }
}
