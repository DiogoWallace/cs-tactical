<?php

namespace App\Modules\Identity\Enums;

/**
 * Capacidades de plataforma (ADR 0012). Cada uma é um Gate com o mesmo nome; os módulos
 * autorizam por elas (`Gate::authorize('manage-academy-content')`, middleware
 * `can:manage-academy-content`), nunca comparando o papel diretamente.
 *
 * Nenhuma capacidade de plataforma dá acesso a dados privados de equipes (ADR 0007): esses
 * dependem só da participação na equipe, verificada pelas Policies do módulo Teams.
 */
enum PlatformAbility: string
{
    /** Criar e publicar conteúdo da Academy (cursos, aulas, quizzes, lineups). */
    case ManageAcademyContent = 'manage-academy-content';

    /** Operar a plataforma (papéis, moderação, configurações globais). */
    case AdministerPlatform = 'administer-platform';
}
