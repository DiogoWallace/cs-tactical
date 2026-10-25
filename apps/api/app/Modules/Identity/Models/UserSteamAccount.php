<?php

namespace App\Modules\Identity\Models;

use Database\Factories\Identity\UserSteamAccountFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Conta Steam vinculada (1:1 com User, etapa M2).
 *
 * Nada é preenchível em massa: o vínculo só nasce de uma resposta OpenID verificada com a Steam
 * (CompleteSteamLink), nunca de dados enviados pelo cliente.
 *
 * @property string $id
 * @property string $user_id
 * @property string $steam_id
 * @property Carbon $linked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[UseFactory(UserSteamAccountFactory::class)]
class UserSteamAccount extends Model
{
    /** @use HasFactory<UserSteamAccountFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
