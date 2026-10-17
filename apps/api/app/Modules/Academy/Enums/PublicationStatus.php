<?php

namespace App\Modules\Academy\Enums;

/**
 * Estado de publicação de cursos, módulos e aulas (ADR 0015, regra R1).
 *
 * `published` → `draft` não é permitido: o que já foi público só pode ser arquivado.
 * Os valores também estão nas check constraints `*_status_check`.
 */
enum PublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public function canTransitionTo(self $next): bool
    {
        return $this === $next || match ($this) {
            self::Draft => true,
            self::Published => $next === self::Archived,
            self::Archived => $next === self::Published,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
