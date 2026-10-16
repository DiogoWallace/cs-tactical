<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Notifications\ConfirmNewEmailNotification;
use App\Modules\Identity\Support\EmailChangeLink;
use Illuminate\Support\Facades\Notification;

/**
 * Guarda o novo e-mail como pendente e envia o link de confirmação a ele (ADR 0011).
 * O e-mail atual continua valendo até a confirmação. Um pedido novo substitui o anterior e
 * invalida os links já enviados (o hash do link depende do endereço pendente).
 */
class RequestEmailChange
{
    public function handle(User $user, string $newEmail): User
    {
        $user->forceFill(['pending_email' => User::normalizeEmail($newEmail)])->save();

        Notification::route('mail', $user->pending_email)
            ->notify(new ConfirmNewEmailNotification(EmailChangeLink::for($user)));

        return $user;
    }

    public function cancel(User $user): User
    {
        $user->forceFill(['pending_email' => null])->save();

        return $user;
    }
}
