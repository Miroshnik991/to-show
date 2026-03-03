<?php

namespace App\Listeners\V1\Broker;

use App\Logging\InteractsWithLogger;
use App\Logging\LogCategory;
use Lime\Kafka\Error;
use Lime\Kafka\Handler\SingleHandler;
use Lime\Kafka\Message\Message;
use Lime\Kafka\Services\KafkaConfigService;

class SingleMessageHandler implements SingleHandler
{
    use InteractsWithLogger;


    public function __construct(
        private readonly KafkaConfigService $kafkaConfigService,
    ) {}

    private const array TOPIC_ALIASES = ['create_payment', 'cancel_payment'];

    public function getTopics(): array
    {
        return $this->kafkaConfigService->getConsumingTopicsByAliases(self::TOPIC_ALIASES);
    }

    protected function getHandler(string $topic): ?BaseSingleMessageHandler
    {
        if ($handler = $this->kafkaConfigService->getHandlerByTopicName($topic)) {
            return app($handler);
        }

        return null;
    }

    public function process(Message $message): bool
    {
        if ($handler = $this->getHandler($message->getTopic())) {
            return $handler->process($message);
        }

        $this->logger()->warning(
            'Handler is not exist',
            $this->logger()->createBuilder()
                ->withCategory(LogCategory::KAFKA_SINGLE_MESSAGE_GET)
                ->withRaw([
                    'topic' =>  $message->getTopic(),
                ])
                ->build()
        );

        return true;
    }

    public function error(Error $error): void
    {
        if ($handler = $this->getHandler($error->getMessage()->topic_name)) {
            $handler->error($error);
        }
    }
}
