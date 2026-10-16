<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Muda o papel de plataforma de um usuário (ADR 0012). Usada pelo comando
 * `identity:set-role`; não há endpoint HTTP para isso na Fase 1.
 *
 * - Recusa rebaixar o último admin, para a plataforma não ficar sem administração.
 * - Registra a mudança no log (auditoria até existir o activity_log da Fase 4).
 * - O novo papel vale na próxima requisição do usuário: o papel é lido do banco a cada vez.
 */
class ChangePlatformRole
{
    /**
     * @return PlatformRole o papel anterior
     *
     * @throws DomainException ao rebaixar o último admin
     */
    public function handle(User $user, PlatformRole $role, string $actor): PlatformRole
    {
        return DB::transaction(function () use ($user, $role, $actor): PlatformRole {
            // Trava as linhas de admins: duas mudanças simultâneas não deixam a plataforma sem nenhum.
            $admins = User::query()->where('platform_role', PlatformRole::Admin->value)->lockForUpdate()->pluck('id');

            $user->refresh();
            $previous = $user->platform_role;

            if ($previous === PlatformRole::Admin && $role !== PlatformRole::Admin && $admins->count() <= 1) {
                throw new DomainException('Não é possível rebaixar o último admin da plataforma.');
            }

            if ($previous !== $role) {
                $user->forceFill(['platform_role' => $role])->save();

                Log::info('identity.platform_role_changed', [
                    'user_id' => $user->getKey(),
                    'from' => $previous->value,
                    'to' => $role->value,
                    'actor' => $actor,
                ]);
            }

            return $previous;
        });
    }
}
