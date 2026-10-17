<?php

namespace App\Modules\Academy\Support;

use RuntimeException;

final class ContentLockedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Outra importação ou limpeza de mídia da Academy está em andamento. Tente de novo quando ela terminar.');
    }
}
