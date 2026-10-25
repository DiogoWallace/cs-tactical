<?php

namespace App\Modules\Matches\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Falha do disco `matches-uploads` (abrir, gravar, ler, medir, renomear) — nunca um conteúdo
 * inválido do jogador (#54). As ações a traduzem em `503 match_upload_storage_unavailable` e o envio
 * continua como estava, para nova tentativa.
 */
final class UploadStorageFailed extends RuntimeException
{
    public function __construct(public readonly string $operation, public readonly string $uploadId, ?Throwable $previous = null)
    {
        parent::__construct("Falha do armazenamento ao {$operation} o envio {$uploadId}.", previous: $previous);
    }
}
