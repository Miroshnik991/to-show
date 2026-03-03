<?php

declare(strict_types=1);

namespace App\Jobs\V1\Broker\Dispatchers;

use App\Contracts\AdvancePayment\AdvancePaymentContract;
use App\Jobs\V1\Broker\ProduceAdvancePayment;
use Lime\Kafka\Services\KafkaConfigService;

readonly class ProduceAdvancePaymentDispatcher
{
    public function __construct(
        private KafkaConfigService $kafkaConfigService,
    ) {}

    public function dispatchJob(AdvancePaymentContract $advancePaymentContract): void
    {
        ProduceAdvancePayment::dispatch($advancePaymentContract, $this->kafkaConfigService);
    }
}
