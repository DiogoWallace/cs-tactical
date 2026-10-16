<?php

namespace App\Modules\System\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail de diagnóstico: confirma que fila, worker e SMTP funcionam juntos. */
class InfrastructureCheckMail extends Mailable
{
    public function __construct(public readonly string $checkId) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "CS Tactical: verificação de infraestrutura ({$this->checkId})");
    }

    public function content(): Content
    {
        $processedAt = now()->toIso8601String();

        return new Content(htmlString: <<<HTML
            <p>Este e-mail foi enviado por um job processado pelo worker de filas.</p>
            <p>Verificação <strong>{$this->checkId}</strong>, processada em {$processedAt}.</p>
            HTML);
    }
}
