<?php

declare(strict_types=1);

namespace App\Jobs\V1\Broker\Dispatchers;

use App\Contracts\PaymentContract;
use App\Jobs\V1\Broker\ProducePayedPayment;
use Lime\Kafka\Services\KafkaConfigService;

readonly class ProducePayedPaymentDispatcher
{
    public function __construct(
        private KafkaConfigService $kafkaConfigService,
    ) {}

    public function dispatchJob(PaymentContract $payment): void
    {
        ProducePayedPayment::dispatch($payment, $this->kafkaConfigService);
    }
}
