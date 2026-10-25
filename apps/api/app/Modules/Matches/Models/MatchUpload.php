<?php

namespace App\Modules\Matches\Models;

use App\Modules\Identity\Models\User;
use App\Modules\Matches\Enums\MatchUploadStatus;
use Database\Factories\Matches\MatchUploadFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Envio de uma demo em partes (etapa M3, docs/training/mvp-m3-envio-de-demos.md).
 *
 * Nada é preenchível em massa: cada campo muda só pelas ações do módulo. O `id` (UUIDv7) também é
 * o nome do arquivo no disco `matches-uploads`.
 *
 * @property string $id
 * @property string $user_id
 * @property MatchUploadStatus $status
 * @property string $name
 * @property int $size_bytes
 * @property int $part_size
 * @property int $parts_total
 * @property int $parts_received
 * @property string|null $last_part_sha256
 * @property string|null $sha256
 * @property string|null $rejection_code
 * @property Carbon $last_activity_at
 * @property Carbon $expires_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[UseFactory(MatchUploadFactory::class)]
class MatchUpload extends Model
{
    /** @use HasFactory<MatchUploadFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => MatchUploadStatus::class,
            'size_bytes' => 'integer',
            'part_size' => 'integer',
            'parts_total' => 'integer',
            'parts_received' => 'integer',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Tamanho esperado da parte `$number` (a última leva o resto). */
    public function expectedPartSize(int $number): int
    {
        return $number < $this->parts_total
            ? $this->part_size
            : $this->size_bytes - $this->part_size * ($this->parts_total - 1);
    }
}
