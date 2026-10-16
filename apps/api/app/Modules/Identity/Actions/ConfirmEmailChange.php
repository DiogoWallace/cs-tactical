<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\EmailChangedNotification;
use App\Modules\Identity\Validation\AccountRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Conclui a troca de e-mail (ADR 0011): o endereço pendente passa a ser o e-mail da conta, já
 * verificado (o clique no link prova a posse). Tokens de redefinição de senha pendentes, que
 * são guardados por e-mail, são apagados, e o endereço antigo recebe um aviso.
 */
class ConfirmEmailChange
{
    /** @throws ValidationException quando outro usuário já usa o endereço */
    public function handle(User $user): User
    {
        $oldEmail = $user->email;
        $newEmail = (string) $user->pending_email;

        if (User::query()->whereEmailIs($newEmail)->whereKeyNot($user->getKey())->exists()) {
            throw ValidationException::withMessages([
                'email' => [__('validation.unique', ['attribute' => __('validation.attributes.email')])],
            ]);
        }

        try {
            DB::transaction(function () use ($user, $oldEmail, $newEmail): void {
                $user->forceFill([
                    'email' => $newEmail,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                ])->save();

                DB::table('password_reset_tokens')->whereIn('email', [$oldEmail, $newEmail])->delete();
            });
        } catch (UniqueConstraintViolationException $e) {
            AccountRules::rethrowUniqueViolation($e);
        }

        Notification::route('mail', $oldEmail)->notify(new EmailChangedNotification($newEmail));

        return $user;
    }
}
