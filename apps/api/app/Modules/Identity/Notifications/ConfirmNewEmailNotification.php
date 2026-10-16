<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Enviada ao **novo** endereço, com o link que conclui a troca de e-mail (ADR 0011). */
class ConfirmNewEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $url)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirme seu novo e-mail')
            ->line('Recebemos um pedido para usar este endereço na sua conta do CS Tactical.')
            ->action('Confirmar novo e-mail', $this->url)
            ->line('O link expira em 60 minutos. Enquanto isso, o e-mail anterior continua valendo.')
            ->line('Se você não fez esse pedido, ignore este e-mail.');
    }
}
