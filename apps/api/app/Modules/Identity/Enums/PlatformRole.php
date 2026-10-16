<?php

namespace App\Modules\Identity\Enums;

/**
 * Papel do usuário na plataforma (ADR 0007). Não dá acesso a dados privados de equipes.
 * As permissões de cada papel são definidas nas Policies e Gates (etapa 1.6).
 *
 * Os valores também estão na check constraint `users_platform_role_check`: um papel novo
 * exige uma migration.
 */
enum PlatformRole: string
{
    case User = 'user';
    case Editor = 'editor';
    case Admin = 'admin';
}
