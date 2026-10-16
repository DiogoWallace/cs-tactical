<?php

namespace App\Modules\System\Http\Controllers;

use App\Modules\System\Actions\CheckSystemHealth;
use Illuminate\Http\JsonResponse;

class HealthController
{
    public function __invoke(CheckSystemHealth $checkSystemHealth): JsonResponse
    {
        $result = $checkSystemHealth->handle();

        $body = [
            'status' => $result['healthy'] ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
        ];

        // Fora do ambiente local, a resposta pública não revela a infraestrutura.
        if (config('health.expose_details')) {
            $body['checks'] = $result['checks'];
        }

        return response()->json($body, $result['healthy'] ? 200 : 503);
    }
}
