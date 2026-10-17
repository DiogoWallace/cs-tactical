<?php

namespace App\Modules\Academy\Content;

/**
 * Erros de validação do conteúdo, cada um com o arquivo e o caminho do campo
 * (ex.: `curso/01-mira/01-aula.yaml: content.blocks[3].alt: obrigatório`).
 */
final class ValidationErrors
{
    /** @var list<string> */
    private array $errors = [];

    public function add(string $location, string $message): void
    {
        $this->errors[] = "{$location}: {$message}";
    }

    public function isEmpty(): bool
    {
        return $this->errors === [];
    }

    public function count(): int
    {
        return count($this->errors);
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->errors;
    }
}
