<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * E-mail de redefinição de senha, enviado pela fila (worker). Além de não atrasar a resposta,
 * isso mantém o tempo de "esqueci minha senha" igual para e-mails cadastrados ou não.
 * O link é montado em IdentityServiceProvider (ResetPassword::createUrlUsing).
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    public function __construct(#[\SensitiveParameter] string $token)
    {
        parent::__construct($token);
        $this->afterCommit();
    }
}
