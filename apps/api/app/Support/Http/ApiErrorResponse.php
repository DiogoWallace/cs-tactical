<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Padroniza o corpo JSON das respostas de erro da API (docs/api/convencoes.md#erros).
 *
 * Todo erro sai como `{ "message": string, "code": string }`, mais `errors` em 422.
 * - `code` é estável e legível por máquina; o frontend decide o texto exibido a partir dele.
 * - Um `code` já definido pela resposta (erro de regra de negócio) é mantido.
 * - Mensagens de 404 são fixas, para não expor nomes de classes ou rotas.
 * - Detalhes de depuração só aparecem em erros 5xx com APP_DEBUG=true.
 */
class ApiErrorResponse
{
    /** @var array<int, array{0: string, 1: string|null}> status => [code, mensagem fixa (null = mantém a original)] */
    private const STATUS_MAP = [
        400 => ['bad_request', null],
        401 => ['unauthenticated', 'Unauthenticated.'],
        403 => ['forbidden', null],
        404 => ['not_found', 'Not found.'],
        405 => ['method_not_allowed', 'Method not allowed.'],
        409 => ['conflict', null],
        413 => ['payload_too_large', 'Payload too large.'],
        419 => ['csrf_token_mismatch', 'CSRF token mismatch.'],
        422 => ['validation_failed', null],
        429 => ['too_many_requests', 'Too Many Attempts.'],
        503 => ['service_unavailable', 'Service Unavailable'],
    ];

    private const DEBUG_KEYS = ['exception', 'file', 'line', 'trace'];

    public function __invoke(Response $response, Throwable $e, Request $request): Response
    {
        if (! $response instanceof JsonResponse || ! $this->appliesTo($request)) {
            return $response;
        }

        $status = $response->getStatusCode();

        if ($status < 400) {
            return $response;
        }

        $original = $response->getData(true);
        $original = is_array($original) ? $original : [];

        $response->setData($this->format($status, $original));

        return $response;
    }

    private function appliesTo(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * @param  array<array-key, mixed>  $original
     * @return array<string, mixed>
     */
    private function format(int $status, array $original): array
    {
        [$code, $fixedMessage] = self::STATUS_MAP[$status] ?? ($status >= 500
            ? ['server_error', 'Server Error']
            : ['http_error', null]);

        $debug = $status >= 500 && config('app.debug');

        $message = match (true) {
            $debug && is_string($original['message'] ?? null) => $original['message'],
            $fixedMessage !== null => $fixedMessage,
            is_string($original['message'] ?? null) && $original['message'] !== '' => $original['message'],
            default => Response::$statusTexts[$status] ?? 'Error',
        };

        $body = [
            'message' => $message,
            'code' => is_string($original['code'] ?? null) ? $original['code'] : $code,
        ];

        if ($status === 422 && isset($original['errors'])) {
            $body['errors'] = $original['errors'];
        }

        if ($debug) {
            foreach (self::DEBUG_KEYS as $key) {
                if (array_key_exists($key, $original)) {
                    $body[$key] = $original[$key];
                }
            }
        }

        return $body;
    }
}
