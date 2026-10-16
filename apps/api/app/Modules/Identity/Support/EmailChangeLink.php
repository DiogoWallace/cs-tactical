<?php

namespace App\Modules\Identity\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Link de confirmação do novo e-mail (ADR 0011).
 *
 * Mesmo desenho da verificação de e-mail (ADR 0010): URL assinada **relativa** da rota da API,
 * com o prefixo trocado pela página /confirmar-email do frontend. O `hash` amarra o link ao
 * endereço pendente: pedir outro endereço invalida os links anteriores.
 */
final class EmailChangeLink
{
    public const EXPIRES_MINUTES = 60;

    private const API_PREFIX = '/api/v1/me/email/confirm/';

    public static function for(User $user): string
    {
        $relative = URL::temporarySignedRoute(
            'v1.me.email.confirm',
            now()->addMinutes(self::EXPIRES_MINUTES),
            ['id' => $user->getKey(), 'hash' => self::hash((string) $user->pending_email)],
            absolute: false,
        );

        return config('app.frontend_url').'/confirmar-email/'.Str::after($relative, self::API_PREFIX);
    }

    public static function hash(string $pendingEmail): string
    {
        return hash('sha256', $pendingEmail);
    }

    public static function matches(User $user, string $hash): bool
    {
        return $user->pending_email !== null && hash_equals(self::hash($user->pending_email), $hash);
    }
}
