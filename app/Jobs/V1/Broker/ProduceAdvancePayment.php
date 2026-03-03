<?php declare(strict_types=1);

namespace App\Jobs\V1\Broker;

use App\Contracts\AdvancePayment\AdvancePaymentContract;
use Lime\Kafka\Jobs\ProducePayload;
use Lime\Kafka\Services\KafkaConfigService;

class ProduceAdvancePayment extends ProducePayload
{
    public const string TOPIC_ALIAS = 'advance_payment';

    public function __construct(
        AdvancePaymentContract $advancePaymentContract,
        private readonly KafkaConfigService $kafkaConfigService,
    ) {
        parent::__construct(
            $advancePaymentContract->__toArray(),
            $this->kafkaConfigService->getTopic(self::TOPIC_ALIAS),
            $advancePaymentContract->getId()
        );
    }
}
