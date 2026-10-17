<?php

namespace App\Modules\Academy\Import;

/** Imagem já validada (tipo pelo conteúdo, tamanho, dimensões), pronta para gravar no disco. */
final readonly class InspectedImage
{
    public function __construct(
        public string $sha256,
        public string $mime,
        public string $extension,
        public int $bytes,
        public int $width,
        public int $height,
        public string $contents,
    ) {}

    /** Caminho no disco `academy`, endereçado pelo hash (ADR 0019); igual ao CHECK de academy_media. */
    public function path(): string
    {
        return MediaStore::pathFor($this->sha256, $this->extension);
    }
}
