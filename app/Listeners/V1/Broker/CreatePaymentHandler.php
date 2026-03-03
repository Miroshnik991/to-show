<?php

namespace App\Listeners\V1\Broker;

use App\Dtos\V1\CreatePaymentDto;
use App\Commands\V1\CreatePaymentCommand;
use App\Logging\InteractsWithLogger;
use App\Logging\LogCategory;
use App\Logging\LogContextBuilder;
use Lime\Kafka\Error;
use Lime\Kafka\Message\Message;

class CreatePaymentHandler extends BaseSingleMessageHandler
{
    use InteractsWithLogger;

    public function __construct(
        protected CreatePaymentCommand $command
    ) {
    }

    public function process(Message $message): bool
    {
        $contextBuilder = $this->logger()->createBuilder(LogCategory::KAFKA_PAYMENT_NEW);
        $body = (array) $message->getBody();
        $contextBuilder->withRaw(['body' => $body]);

        $this->prepareLogContext($contextBuilder, $body);

        $this->logger()->info('Request new payment via kafka', $contextBuilder->build());

        try {
            $dto = CreatePaymentDto::fromBrokerMessage($message);
            $this->command->handle($dto);
        } catch (\Throwable $e) {
            $this->logger()->logException($e, $contextBuilder->build());
            return false;
        }

        return true;
    }

    public function error(Error $error): void
    {
        // TODO: Implement error() method.
    }


    protected function prepareLogContext(LogContextBuilder $logContextBuilder, array $body): void
    {
        if ($orderNumber = ($body['order']['number'] ?? '')) {
            $logContextBuilder->withOrderNumber($orderNumber);
        }
        if ($orderGuid = ($body['order']['guid'] ?? '')) {
            $logContextBuilder->withOrderGuid($orderGuid);
        }
        if ($guid = ($body['guid'] ?? '')) {
            $logContextBuilder->withPaymentGuid($guid);
        }
        if ($userId = ($body['order']['customer']['id'] ?? '')) {
            $logContextBuilder->withUserId($userId);
        }
        if ($type = ($body['type'] ?? '')) {
            $logContextBuilder->withPaymentType($type);
        }}
}
