<?php

namespace App\Modules\System\Console;

use App\Modules\System\Jobs\RunInfrastructureCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CheckInfrastructureCommand extends Command
{
    protected $signature = 'system:check-infra {--to=dev@cstactical.local : Destinatário do e-mail de teste}';

    protected $description = 'Enfileira um job que envia um e-mail de teste (valida Redis, worker e SMTP)';

    public function handle(): int
    {
        $checkId = Str::lower(Str::random(8));

        RunInfrastructureCheck::dispatch($checkId, (string) $this->option('to'));

        $this->components->info("Job enfileirado (verificação {$checkId}).");
        $this->line('Acompanhe com `make logs s=worker` e veja o e-mail em http://localhost:8025.');

        return self::SUCCESS;
    }
}
