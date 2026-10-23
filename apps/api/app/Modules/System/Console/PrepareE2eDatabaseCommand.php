<?php

namespace App\Modules\System\Console;

use App\Modules\Identity\Models\User;
use App\Modules\Learning\Actions\CompleteLesson;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan e2e:prepare
 *
 * Recria o banco da stack E2E e cria as contas usadas pelo Playwright (docs/development/e2e.md).
 * Com `--seed-history`, depois da importação das fixtures, só grava o histórico do curso que o
 * `scripts/e2e.sh` arquiva em seguida (nada é recriado).
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
        'navigation@e2e.test' => ['nickname' => 'e2e_navigation', 'verified' => true],
        'navigation-mobile@e2e.test' => ['nickname' => 'e2e_nav_mobile', 'verified' => true],
        'navigation-a11y@e2e.test' => ['nickname' => 'e2e_navigation_a11y', 'verified' => true],
        'dashboard@e2e.test' => ['nickname' => 'e2e_dashboard', 'verified' => true],
        'history@e2e.test' => ['nickname' => 'e2e_history', 'verified' => true],
    ];

    /**
     * Histórico de curso arquivado (etapa 2.7a, R4): a conta conclui as aulas do curso de fixture
     * enquanto ele é público; depois o `scripts/e2e.sh` reimporta o curso como arquivado
     * (`apps/web/e2e/fixtures/academy/_arquivado`).
     */
    public const HISTORY_ACCOUNT = 'history@e2e.test';

    public const HISTORY_COURSE = 'historico-e2e';

    /** @var list<string> */
    public const HISTORY_LESSONS = ['primeira-aula', 'segunda-aula'];

    protected $signature = 'e2e:prepare
        {--seed-history : Só grava o histórico do curso de fixture que será arquivado (depois da importação)}';

    protected $description = 'Recria o banco E2E (cs_tactical_e2e) e cria as contas de teste';

    public function handle(CompleteLesson $completeLesson): int
    {
        if (($problem = $this->unsafeReason()) !== null) {
            $this->error("Recusado: {$problem}. Nada foi alterado.");

            return self::FAILURE;
        }

        if ($this->option('seed-history')) {
            return $this->seedHistory($completeLesson);
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

    /** Pela Action pública do Learning (auto-matrícula, R8), com o curso ainda publicado. */
    private function seedHistory(CompleteLesson $completeLesson): int
    {
        $user = User::whereEmailIs(self::HISTORY_ACCOUNT)->first();
        if ($user === null) {
            $this->error('Conta '.self::HISTORY_ACCOUNT.' não encontrada: rode e2e:prepare antes.');

            return self::FAILURE;
        }

        foreach (self::HISTORY_LESSONS as $lesson) {
            if ($completeLesson->handle($user, self::HISTORY_COURSE, $lesson) === null) {
                $this->error('Aula '.self::HISTORY_COURSE."/{$lesson} não é pública: importe as fixtures antes.");

                return self::FAILURE;
            }
        }

        $this->info('Histórico E2E gravado: '.count(self::HISTORY_LESSONS).' aulas de '.self::HISTORY_COURSE.'.');

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
