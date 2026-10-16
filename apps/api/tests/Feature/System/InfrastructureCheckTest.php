<?php

use App\Modules\System\Jobs\RunInfrastructureCheck;
use App\Modules\System\Mail\InfrastructureCheckMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

it('queues the infrastructure check job', function () {
    Queue::fake();

    $this->artisan('system:check-infra', ['--to' => 'qa@cstactical.local'])->assertSuccessful();

    Queue::assertPushed(
        RunInfrastructureCheck::class,
        fn (RunInfrastructureCheck $job) => $job->recipient === 'qa@cstactical.local',
    );
});

it('sends the check e-mail when the job runs', function () {
    Mail::fake();

    (new RunInfrastructureCheck('abc12345', 'qa@cstactical.local'))->handle();

    Mail::assertSent(
        InfrastructureCheckMail::class,
        fn (InfrastructureCheckMail $mail) => $mail->hasTo('qa@cstactical.local') && $mail->checkId === 'abc12345',
    );
});

it('renders the check e-mail without a view file', function () {
    $mail = new InfrastructureCheckMail('abc12345');

    $mail->assertHasSubject('CS Tactical: verificação de infraestrutura (abc12345)');
    $mail->assertSeeInHtml('abc12345');
});
