<?php

namespace App\Modules\Identity\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Aviso de segurança enviado ao endereço **antigo** depois que a troca foi concluída.
 * O novo endereço aparece mascarado.
 */
class EmailChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $newEmail)
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
            ->subject('O e-mail da sua conta foi alterado')
            ->line('O e-mail da sua conta do CS Tactical foi alterado para '.self::mask($this->newEmail).'.')
            ->line('A partir de agora, use o novo endereço para entrar e recuperar a senha.')
            ->line('Se não foi você, responda a este e-mail ou fale com o suporte imediatamente.');
    }

    /** "jogador@exemplo.com" => "jo*****@exemplo.com" */
    public static function mask(string $email): string
    {
        $local = Str::before($email, '@');
        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $visible.str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))).'@'.Str::after($email, '@');
    }
}
