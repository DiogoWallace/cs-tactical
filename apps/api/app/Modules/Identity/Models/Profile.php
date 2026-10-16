<?php

namespace App\Modules\Identity\Models;

use Database\Factories\Identity\ProfileFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Perfil público do jogador (1:1 com User).
 *
 * - `user_id` não é preenchível em massa: o perfil é criado pela relação do usuário.
 * - O apelido é exibido como o jogador escreveu, mas é único sem diferenciar maiúsculas
 *   (índice em lower(nickname)).
 *
 * @property string $id
 * @property string $user_id
 * @property string $nickname
 * @property string|null $country_code
 * @property string $timezone
 * @property string|null $bio
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[UseFactory(ProfileFactory::class)]
class Profile extends Model
{
    /** @use HasFactory<ProfileFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'nickname',
        'country_code',
        'timezone',
        'bio',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'timezone' => 'UTC',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Busca pelo apelido sem diferenciar maiúsculas (usa o índice lower(nickname)).
     *
     * @param  Builder<Profile>  $query
     */
    public function scopeWhereNicknameIs(Builder $query, string $nickname): void
    {
        $query->whereRaw('lower(nickname) = lower(?)', [trim($nickname)]);
    }

    /** @return Attribute<string, string> */
    protected function nickname(): Attribute
    {
        return Attribute::make(set: fn (string $value) => trim($value));
    }

    /** @return Attribute<string|null, string|null> */
    protected function countryCode(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : strtoupper(trim($value)));
    }
}
