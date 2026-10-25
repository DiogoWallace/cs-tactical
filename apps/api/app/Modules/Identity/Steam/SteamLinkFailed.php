<?php

namespace App\Modules\Identity\Steam;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Motivo estável de um vínculo com a Steam recusado (docs/training/mvp-m2-vinculo-steam.md). Sai
 * como `{message, code}` com o status próprio; o frontend escolhe o texto pelo `code`. Nenhum
 * detalhe da resposta recebida vai para a mensagem.
 */
final class SteamLinkFailed extends RuntimeException
{
    private function __construct(public readonly string $errorCode, public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function stateInvalid(): self
    {
        return new self('steam_state_invalid', 422, 'O vínculo com a Steam expirou ou não foi iniciado nesta sessão.');
    }

    public static function cancelled(): self
    {
        return new self('steam_cancelled', 422, 'O vínculo foi cancelado na Steam.');
    }

    public static function responseInvalid(): self
    {
        return new self('steam_response_invalid', 422, 'A resposta da Steam não é válida.');
    }

    public static function replay(): self
    {
        return new self('steam_replay', 422, 'Esta resposta da Steam já foi usada ou está fora do prazo.');
    }

    public static function notVerified(): self
    {
        return new self('steam_not_verified', 422, 'A Steam não confirmou a resposta.');
    }

    public static function unavailable(): self
    {
        return new self('steam_unavailable', 503, 'Não foi possível falar com a Steam agora.');
    }

    public static function alreadyLinked(): self
    {
        return new self('steam_already_linked', 409, 'Sua conta já tem uma conta Steam vinculada.');
    }

    public static function accountInUse(): self
    {
        return new self('steam_account_in_use', 409, 'Esta conta Steam já está vinculada a outra conta.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
