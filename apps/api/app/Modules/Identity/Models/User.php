<?php

namespace App\Modules\Identity\Models;

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Notifications\ResetPasswordNotification;
use App\Modules\Identity\Notifications\VerifyEmailNotification;
use Database\Factories\Identity\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * Conta de acesso. Os dados públicos do jogador ficam em Profile (1:1).
 *
 * - `platform_role` e `email_verified_at` não são preenchíveis em massa: mudam só por
 *   ações explícitas (comando administrativo, verificação de e-mail).
 * - O e-mail é gravado sem espaços nas pontas e em minúsculas. A unicidade sem diferenciar
 *   maiúsculas é garantida no banco (índice em lower(email)).
 * - Verificação de e-mail e redefinição de senha usam notificações próprias, enviadas pela fila.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $pending_email
 * @property string $password
 * @property PlatformRole $platform_role
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Profile|null $profile
 * @property-read UserSteamAccount|null $steamAccount
 */
#[UseFactory(UserFactory::class)]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'platform_role' => 'user',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'platform_role' => PlatformRole::class,
        ];
    }

    /** @return HasOne<Profile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /** @return HasOne<UserSteamAccount, $this> */
    public function steamAccount(): HasOne
    {
        return $this->hasOne(UserSteamAccount::class);
    }

    public function hasPlatformRole(PlatformRole $role): bool
    {
        return $this->platform_role === $role;
    }

    /**
     * Busca pelo e-mail sem diferenciar maiúsculas.
     *
     * @param  Builder<User>  $query
     */
    public function scopeWhereEmailIs(Builder $query, string $email): void
    {
        $query->where('email', self::normalizeEmail($email));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /** @param  string  $token */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @return Attribute<string, string> */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (string $value) => self::normalizeEmail($value));
    }
}
