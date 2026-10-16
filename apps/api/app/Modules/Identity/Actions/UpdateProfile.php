<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Validation\AccountRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Atualiza nome (users) e dados públicos (profiles) numa transação.
 * Recebe dados já validados; campos ausentes não mudam.
 */
class UpdateProfile
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data): User
    {
        try {
            DB::transaction(function () use ($user, $data): void {
                if (array_key_exists('name', $data)) {
                    $user->update(['name' => $data['name']]);
                }

                $profileData = Arr::only($data, ['nickname', 'country_code', 'timezone', 'bio']);
                if ($profileData !== [] && $user->profile !== null) {
                    $user->profile->update($profileData);
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            AccountRules::rethrowUniqueViolation($e);
        }

        return $user->refresh()->load('profile');
    }
}
