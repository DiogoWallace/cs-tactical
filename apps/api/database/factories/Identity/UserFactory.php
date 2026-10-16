<?php

namespace Database\Factories\Identity;

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Usuário verificado, papel `user` e sem perfil. Use `withProfile()` para o caso real
 * (toda conta criada pelo cadastro tem perfil).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** Senha de todos os usuários de teste: "password". O hash é calculado uma única vez. */
    protected static ?string $password = null;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'platform_role' => PlatformRole::User,
            'remember_token' => Str::random(10),
        ];
    }

    public function withProfile(): static
    {
        return $this->has(ProfileFactory::new(), 'profile');
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function editor(): static
    {
        return $this->state(['platform_role' => PlatformRole::Editor]);
    }

    public function admin(): static
    {
        return $this->state(['platform_role' => PlatformRole::Admin]);
    }
}
