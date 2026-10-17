<?php

namespace Database\Factories\Academy;

use App\Modules\Academy\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Registro de mídia coerente com as constraints (caminho derivado do hash). Não grava arquivo:
 * testes que precisam do arquivo usam o MediaStore com um disco descartável.
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sha = hash('sha256', Str::random(32));

        return [
            'sha256' => $sha,
            'path' => 'media/'.substr($sha, 0, 2).'/'.$sha.'.png',
            'mime' => 'image/png',
            'bytes' => 1024,
            'width' => 640,
            'height' => 360,
            'created_at' => now(),
        ];
    }
}
