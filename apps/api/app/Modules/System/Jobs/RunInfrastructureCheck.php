<?php

namespace App\Modules\System\Jobs;

use App\Modules\System\Mail\InfrastructureCheckMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RunInfrastructureCheck implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $checkId,
        public readonly string $recipient,
    ) {}

    public function handle(): void
    {
        Mail::to($this->recipient)->send(new InfrastructureCheckMail($this->checkId));

        Log::info('Infrastructure check processed', ['check_id' => $this->checkId]);
    }
}
