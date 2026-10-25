<?php

namespace App\Modules\Matches\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Motivo estável de um envio de demo recusado (docs/training/mvp-m3-envio-de-demos.md#regras).
 * Sai como `{message, code}` com o status próprio; na recusa do arquivo, o motivo vai no próprio
 * `code` (`match_upload_not_a_demo`, `match_upload_size_mismatch`) e fica gravado no envio.
 */
final class MatchUploadFailed extends RuntimeException
{
    /** @param  array<string, string>  $headers */
    private function __construct(
        public readonly string $errorCode,
        public readonly int $status,
        string $message,
        private readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function steamLinkRequired(): self
    {
        return new self('steam_link_required', 409, 'Vincule sua conta Steam antes de enviar uma demo.');
    }

    public static function alreadyOpen(): self
    {
        return new self('match_upload_already_open', 409, 'Já existe um envio em andamento.');
    }

    public static function quotaExceeded(int $retryAfterSeconds): self
    {
        return new self('match_upload_quota_exceeded', 429, 'Limite de envios das últimas 24 horas atingido.', headers: [
            'Retry-After' => (string) max(1, $retryAfterSeconds),
        ]);
    }

    public static function storageUnavailable(): self
    {
        return new self('match_upload_storage_unavailable', 503, 'Sem espaço para receber o arquivo agora.');
    }

    public static function notOpen(): self
    {
        return new self('match_upload_not_open', 409, 'Este envio não está mais aberto.');
    }

    public static function partOutOfOrder(): self
    {
        return new self('match_upload_part_out_of_order', 409, 'Parte fora de ordem.');
    }

    public static function partSizeInvalid(): self
    {
        return new self('match_upload_part_size_invalid', 422, 'Tamanho da parte inválido.');
    }

    public static function partCorrupted(): self
    {
        return new self('match_upload_part_corrupted', 422, 'A parte chegou diferente do que foi enviado.');
    }

    public static function incomplete(): self
    {
        return new self('match_upload_incomplete', 409, 'Ainda faltam partes do arquivo.');
    }

    /** O arquivo montado tem outro tamanho (C4); o arquivo já foi apagado. */
    public static function sizeMismatch(): self
    {
        return new self('match_upload_size_mismatch', 422, 'O arquivo montado não tem o tamanho declarado.');
    }

    /** Sem a assinatura de demo do Source 2 (C5); o arquivo já foi apagado. */
    public static function notADemo(): self
    {
        return new self('match_upload_not_a_demo', 422, 'O arquivo não é uma demo do CS2.');
    }

    public static function duplicate(): self
    {
        return new self('match_upload_duplicate', 409, 'Você já enviou esta demo.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status, $this->headers);
    }
}
