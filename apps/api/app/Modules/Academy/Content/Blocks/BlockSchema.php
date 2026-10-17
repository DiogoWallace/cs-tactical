<?php

namespace App\Modules\Academy\Content\Blocks;

use App\Modules\Academy\Content\BlockContext;
use App\Modules\Academy\Content\ValidationErrors;

/**
 * Schema de um tipo de bloco numa versão do conteúdo (ADR 0016). Recebe os campos do arquivo
 * (sem `type`) e devolve o `data` canônico que vai para o banco, ou null se houver erro.
 * Validação estrita: campo desconhecido reprova.
 */
interface BlockSchema
{
    public function type(): string;

    /**
     * @param  array<array-key, mixed>  $fields
     * @return array<string, mixed>|null
     */
    public function normalize(array $fields, string $location, BlockContext $context, ValidationErrors $errors): ?array;
}
