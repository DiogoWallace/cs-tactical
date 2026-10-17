<?php

namespace App\Modules\Academy\Content;

/**
 * O que um bloco precisa saber da aula que está sendo validada: as imagens do curso e o quiz
 * da própria aula.
 */
interface BlockContext
{
    /**
     * Valida a imagem `$src` (nome de arquivo na pasta `media/` do curso) e devolve o sha256 dela,
     * ou null (com o erro registrado) se não existir ou for inválida.
     */
    public function mediaSha(string $src, string $location, ValidationErrors $errors): ?string;

    /** `key` do quiz declarado na própria aula, ou null se a aula não tem quiz. */
    public function lessonQuizKey(): ?string;
}
