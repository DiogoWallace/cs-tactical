<?php

namespace App\Modules\System\Console;

use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan e2e:prepare
 *
 * Recria o banco da stack E2E e cria as contas usadas pelo Playwright (docs/development/e2e.md).
 *
 * Proteções contra apagar outro banco (todas precisam passar):
 * - o comando só é registrado com APP_ENV=e2e (SystemServiceProvider);
 * - confere de novo o ambiente ao rodar;
 * - a conexão padrão tem de ser pgsql com o banco `cs_tactical_e2e`, tanto na configuração
 *   quanto no `current_database()` do servidor conectado.
 * Além disso, a stack E2E (compose.e2e.yaml) roda num projeto e numa rede próprios, com Postgres
 * em tmpfs: o banco de desenvolvimento nem é alcançável de lá.
 */
class PrepareE2eDatabaseCommand extends Command
{
    public const DATABASE = 'cs_tactical_e2e';

    /** Senha das contas E2E. Igual a E2E_PASSWORD em apps/web/e2e/support/accounts.ts. */
    public const PASSWORD = 'e2e-Senha-Segura-123';

    /**
     * Uma conta por fluxo que altera dados, para os testes rodarem em paralelo sem interferir.
     * Igual a ACCOUNTS em apps/web/e2e/support/accounts.ts.
     *
     * @var array<string, array{nickname: string, verified: bool}>
     */
    private const ACCOUNTS = [
        'login@e2e.test' => ['nickname' => 'e2e_login', 'verified' => true],
        'remember@e2e.test' => ['nickname' => 'e2e_remember', 'verified' => true],
        'session@e2e.test' => ['nickname' => 'e2e_session', 'verified' => true],
        'guard@e2e.test' => ['nickname' => 'e2e_guard', 'verified' => true],
        'redirect@e2e.test' => ['nickname' => 'e2e_redirect', 'verified' => true],
        'verify@e2e.test' => ['nickname' => 'e2e_verify', 'verified' => false],
        'reset@e2e.test' => ['nickname' => 'e2e_reset', 'verified' => true],
        'profile@e2e.test' => ['nickname' => 'e2e_profile', 'verified' => true],
        'email@e2e.test' => ['nickname' => 'e2e_email', 'verified' => true],
        'password@e2e.test' => ['nickname' => 'e2e_password', 'verified' => true],
        'mobile@e2e.test' => ['nickname' => 'e2e_mobile', 'verified' => true],
        'a11y@e2e.test' => ['nickname' => 'e2e_a11y', 'verified' => false],
        'csp@e2e.test' => ['nickname' => 'e2e_csp', 'verified' => true],
        'learning@e2e.test' => ['nickname' => 'e2e_learning', 'verified' => true],
        'learning-a11y@e2e.test' => ['nickname' => 'e2e_learning_a11y', 'verified' => true],
        'quiz@e2e.test' => ['nickname' => 'e2e_quiz', 'verified' => true],
        'quiz-a11y@e2e.test' => ['nickname' => 'e2e_quiz_a11y', 'verified' => true],
    ];

    protected $signature = 'e2e:prepare';

    protected $description = 'Recria o banco E2E (cs_tactical_e2e) e cria as contas de teste';

    public function handle(): int
    {
        if (($problem = $this->unsafeReason()) !== null) {
            $this->error("Recusado: {$problem}. Nada foi alterado.");

            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);

        foreach (self::ACCOUNTS as $email => $account) {
            $user = new User;
            $user->forceFill([
                'name' => 'Conta '.$account['nickname'],
                'email' => $email,
                'password' => self::PASSWORD,
                'email_verified_at' => $account['verified'] ? now() : null,
            ])->save();

            $user->profile()->create([
                'nickname' => $account['nickname'],
                'country_code' => 'BR',
                'timezone' => 'America/Sao_Paulo',
            ]);
        }

        $this->info(count(self::ACCOUNTS).' contas E2E criadas em '.self::DATABASE.'.');

        return self::SUCCESS;
    }

    private function unsafeReason(): ?string
    {
        if (! $this->laravel->environment('e2e')) {
            return 'APP_ENV precisa ser e2e';
        }

        $connection = (string) config('database.default');
        if (config("database.connections.{$connection}.driver") !== 'pgsql') {
            return 'a conexão padrão precisa ser PostgreSQL';
        }

        if (config("database.connections.{$connection}.database") !== self::DATABASE) {
            return 'DB_DATABASE precisa ser '.self::DATABASE;
        }

        $current = DB::connection($connection)->scalar('select current_database()');
        if ($current !== self::DATABASE) {
            return 'o servidor conectado não está no banco '.self::DATABASE;
        }

        return null;
    }
}
