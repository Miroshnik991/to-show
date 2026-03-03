<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\PollPendingPaymentsService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

classPollPendingPayments extends Command
{
    protected $signature = 'payments:poll-pending';
    protected $description = 'Poll payments pending confirmation';

    public function __construct(protected readonly PollPendingPaymentsService $service)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        Payment::pending()
            ->with('paymentSystem')
            ->chunk(100, function ($payments) {
                $this->service->processPayments($payments);
            });

        return SymfonyCommand::SUCCESS;
    }
}
