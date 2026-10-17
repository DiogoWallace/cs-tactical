<?php

namespace App\Modules\Academy\Import;

use App\Modules\Academy\Content\ValidationErrors;

/**
 * Imagens da pasta `media/` de um curso. Cada arquivo é lido e validado uma vez; só entram no
 * plano as imagens referenciadas (capa ou bloco `image`).
 */
final class CourseMedia
{
    /** @var array<string, InspectedImage|null> por nome de arquivo */
    private array $inspected = [];

    public function __construct(
        private readonly string $directory,
        private readonly string $relativeDirectory,
        private readonly ImageInspector $inspector,
    ) {}

    public function sha(string $src, string $location, ValidationErrors $errors): ?string
    {
        if (preg_match(ImageInspector::FILENAME_PATTERN, $src) !== 1) {
            $errors->add($location, 'nome de arquivo inválido (só minúsculas, números, ".", "_" e "-", sem pastas)');

            return null;
        }

        if (! array_key_exists($src, $this->inspected)) {
            $this->inspected[$src] = $this->read($src, $location, $errors);
        } elseif ($this->inspected[$src] === null) {
            $errors->add($location, "imagem {$this->relativeDirectory}/{$src} inválida (veja o erro acima)");
        }

        return $this->inspected[$src]?->sha256;
    }

    /** @return array<string, InspectedImage> por sha256 */
    public function images(): array
    {
        $images = [];
        foreach (array_filter($this->inspected) as $image) {
            $images[$image->sha256] = $image;
        }

        return $images;
    }

    private function read(string $src, string $location, ValidationErrors $errors): ?InspectedImage
    {
        $path = $this->directory.'/'.$src;
        if (! is_file($path) || is_link($path) || is_link($this->directory)) {
            $errors->add($location, "imagem {$this->relativeDirectory}/{$src} não encontrada");

            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            $errors->add($location, "não foi possível ler {$this->relativeDirectory}/{$src}");

            return null;
        }

        return $this->inspector->inspect($src, $contents, "{$location} ({$this->relativeDirectory}/{$src})", $errors);
    }
}
