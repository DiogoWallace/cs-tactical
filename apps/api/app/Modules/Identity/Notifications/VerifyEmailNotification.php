<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * E-mail de verificação, enviado pela fila (worker) para não atrasar o cadastro.
 * O link é montado em IdentityServiceProvider (VerifyEmail::createUrlUsing) e o texto vem das
 * traduções em lang/pt_BR.json.
 */
class VerifyEmailNotification extends VerifyEmail implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->afterCommit();
    }
}
