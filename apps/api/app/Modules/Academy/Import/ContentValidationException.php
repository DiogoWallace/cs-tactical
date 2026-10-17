<?php

namespace App\Modules\Academy\Import;

use App\Modules\Academy\Content\ValidationErrors;
use RuntimeException;

/** O conteúdo tem erros; nada foi gravado (nem no banco nem no disco). */
final class ContentValidationException extends RuntimeException
{
    public function __construct(public readonly ValidationErrors $errors)
    {
        parent::__construct("Conteúdo inválido: {$errors->count()} erro(s).");
    }
}
