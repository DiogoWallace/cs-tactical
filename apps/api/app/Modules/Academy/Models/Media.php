<?php

namespace App\Modules\Academy\Models;

use Database\Factories\Academy\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Imagem registrada no disco `academy` (ADR 0019). Imutável: criada pela importação, depois que
 * o arquivo existe e teve o hash conferido; nunca alterada nem apagada na Fase 2.
 *
 * @property string $id
 * @property string $sha256
 * @property string $path
 * @property string $mime
 * @property int $bytes
 * @property int $width
 * @property int $height
 * @property Carbon $created_at
 */
#[UseFactory(MediaFactory::class)]
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'academy_media';

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
