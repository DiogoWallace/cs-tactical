<?php

namespace App\Modules\Identity\Enums;

/**
 * Papel do usuário na plataforma (ADR 0007 e ADR 0012). Não dá acesso a dados privados de
 * equipes.
 *
 * `abilities()` é a matriz de capacidades: a única fonte da regra. Os Gates são registrados a
 * partir dela (IdentityServiceProvider) e o restante do código autoriza pelos Gates.
 *
 * Os valores também estão na check constraint `users_platform_role_check`: um papel novo
 * exige uma migration.
 */
enum PlatformRole: string
{
    case User = 'user';
    case Editor = 'editor';
    case Admin = 'admin';

    /** @return list<PlatformAbility> */
    public function abilities(): array
    {
        return match ($this) {
            self::User => [],
            self::Editor => [PlatformAbility::ManageAcademyContent],
            self::Admin => [PlatformAbility::ManageAcademyContent, PlatformAbility::AdministerPlatform],
        };
    }

    public function grants(PlatformAbility $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role) => $role->value, self::cases());
    }
}
