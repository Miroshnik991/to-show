<?php

namespace App\Jobs\V1\Broker;

use App\Contracts\PaymentContract;
use Lime\Kafka\Jobs\ProducePayload;
use Lime\Kafka\Services\KafkaConfigService;

class ProduceCancelledPayment extends ProducePayload
{
    private const string TOPIC_ALIAS = 'payment_was_cancelled';

    public function __construct(
        PaymentContract                     $payment,
        private readonly KafkaConfigService $kafkaConfigService,
    ) {
        $payload = [
            'guid' => $payment->getId(),
            'reason' => $payment->getCancel()->reason,
            'cancelled_at' => $payment->getCancel()->date->toIso8601String(),
        ];

        parent::__construct($payload, $this->kafkaConfigService->getTopic(self::TOPIC_ALIAS));
    }
}
