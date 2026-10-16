<?php

namespace Database\Factories\Identity;

use App\Modules\Identity\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sem `for(...)`, cria também o usuário dono do perfil.
 *
 * @extends Factory<Profile>
 */
class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            // Respeita profiles_nickname_format_check: 3 a 24 caracteres [A-Za-z0-9_.-].
            'nickname' => fake()->unique()->regexify('[A-Za-z][A-Za-z0-9_]{5,15}'),
            'country_code' => fake()->countryCode(),
            'timezone' => fake()->timezone(),
            'bio' => fake()->optional()->sentence(),
        ];
    }
}
