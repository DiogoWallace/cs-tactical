<?php

namespace Database\Factories\Identity;

use App\Modules\Identity\Models\UserSteamAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Sem `for(...)`, cria também o usuário.
 *
 * @extends Factory<UserSteamAccount>
 */
class UserSteamAccountFactory extends Factory
{
    protected $model = UserSteamAccount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            // Respeita user_steam_accounts_steam_id_format_check: 7656119 + 10 dígitos.
            'steam_id' => '7656119'.fake()->unique()->numerify('##########'),
            'linked_at' => now(),
        ];
    }
}
