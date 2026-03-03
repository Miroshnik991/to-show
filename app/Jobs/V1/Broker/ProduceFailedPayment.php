<?php

namespace App\Jobs\V1\Broker;

use App\Contracts\PaymentContract;
use App\Jobs\V1\Broker\Dispatchers\ProduceFailedPaymentDispatcher;
use Lime\Kafka\Jobs\ProducePayload;
use Lime\Kafka\Services\KafkaConfigService;

class ProduceFailedPayment extends ProducePayload
{
    private const string TOPIC_ALIAS = 'payment_was_failed';

    public function __construct(
        PaymentContract $payment,
        private readonly KafkaConfigService $kafkaConfigService,
    ) {
        $payload = [
            'guid' => $payment->getId(),
            'reason' => '',
        ];

        parent::__construct($payload, $this->kafkaConfigService->getTopic(self::TOPIC_ALIAS));
    }
}
