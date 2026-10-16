<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Actions\ChangePlatformRole;
use App\Modules\Identity\Enums\PlatformRole;
use App\Modules\Identity\Models\User;
use DomainException;
use Illuminate\Console\Command;

/**
 * php artisan identity:set-role {email} {role} [--force]
 *
 * Única forma de mudar papéis na Fase 1 (sem tela administrativa). Roda só dentro do container,
 * como o `system:check-infra`.
 */
class SetPlatformRoleCommand extends Command
{
    protected $signature = 'identity:set-role
        {email : E-mail da conta (sem diferenciar maiúsculas)}
        {role : user, editor ou admin}
        {--force : Não pede confirmação}';

    protected $description = 'Muda o papel de plataforma de um usuário (user, editor, admin)';

    public function handle(ChangePlatformRole $changePlatformRole): int
    {
        $role = PlatformRole::tryFrom(strtolower(trim((string) $this->argument('role'))));
        if ($role === null) {
            $this->error('Papel inválido. Use um destes: '.implode(', ', PlatformRole::values()).'.');

            return self::INVALID;
        }

        $user = User::query()->whereEmailIs((string) $this->argument('email'))->first();
        if ($user === null) {
            $this->error('Nenhuma conta com esse e-mail.');

            return self::FAILURE;
        }

        if ($user->platform_role === $role) {
            $this->info("A conta já tem o papel {$role->value}. Nada mudou.");

            return self::SUCCESS;
        }

        $question = "Mudar o papel de {$user->email} de {$user->platform_role->value} para {$role->value}?";
        if (! $this->option('force') && ! $this->confirm($question)) {
            $this->warn('Cancelado. Nada mudou.');

            return self::FAILURE;
        }

        try {
            $previous = $changePlatformRole->handle($user, $role, actor: 'console');
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Papel de {$user->email}: {$previous->value} → {$role->value}.");

        return self::SUCCESS;
    }
}
