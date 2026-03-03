<?php

namespace App\Jobs\V1\Broker;

use App\Contracts\PaymentContract;
use Lime\Kafka\Jobs\ProducePayload;
use Lime\Kafka\Services\KafkaConfigService;

class ProducePayedPayment extends ProducePayload
{
    private const string TOPIC_ALIAS = 'payment_was_payed';

    public function __construct(
        PaymentContract $payment,
        private readonly KafkaConfigService $kafkaConfigService,
    ) {
        $payload = [
            'guid' => $payment->getId(),
        ];

        parent::__construct($payload, $this->kafkaConfigService->getTopic(self::TOPIC_ALIAS));
    }
}
