<?php

declare(strict_types=1);

namespace App\Jobs\V1\Broker\Dispatchers;

use App\Contracts\PaymentContract;
use App\Jobs\V1\Broker\ProduceFailedPayment;
use Lime\Kafka\Services\KafkaConfigService;

readonly class ProduceFailedPaymentDispatcher
{
    public function __construct(
        private KafkaConfigService $kafkaConfigService,
    ) {}

    public function dispatchJob(PaymentContract $payment): void
    {
        ProduceFailedPayment::dispatch($payment, $this->kafkaConfigService);
    }
}
