<?php

declare(strict_types=1);

namespace App\Listeners\V1\Broker;

use App\Commands\V1\CancelPaymentCommand;
use App\Entities\Payment as PaymentEntity;
use App\Listeners\V1\Broker\Traits\HandlesPaymentByGuid;
use App\Logging\LogCategory;
use App\Models\Payment;
use App\Models\PaymentSystem;
use App\ValueObjects\Cancel;
use Lime\Kafka\Message\Message;

class CancelPaymentHandler extends BaseSingleMessageHandler
{
    use HandlesPaymentByGuid;


    private const string ACTION_NAME = 'Cancel payment';
    private const string SKIPPING_MESSAGE = 'Skipping cancel';
    private const string CANCELLATION_REASON = 'Cancel payment via broker';


    public function __construct(
        private readonly CancelPaymentCommand $command,
    ) {}

    public function process(Message $message): bool
    {
        $body = $message->getBody();
        $contextBuilder = $this->logger()->createBuilder($this->getLogCategory())->withRaw($body);
        $context = $contextBuilder->build();

        $this->logger()->info("Request new {$this->getActionName()} via broker", $context);

        try {
            $guids = $body['payment_guids'] ?? null;
            if (empty($guids)) {
                $this->logger()->critical("Empty guids into " . self::class, $context);
                return false;
            }

            /** @var Payment|null $payment */
            $payments = Payment::whereIn(Payment::FIELD_ID, $guids)->get();

            if ($payments->count() !== count($guids)) {
                $this->logger()->info("Undefined payment guids from $guids  – {$this->getSkippingMessage()}", $context);
                return true;
            }

            $payments->each(function (Payment $payment) {
                $this->executeCommand(
                    PaymentEntity::fromEloquent($payment),
                    $payment->paymentSystem,
                );
            });
            return true;
        } catch (\Throwable $throwable) {
            $this->logger()->critical($throwable->getMessage(), $context);
            return false;
        }
    }


    protected function getLogCategory(): LogCategory
    {
        return LogCategory::KAFKA_PAYMENT_CANCELLED_GET;
    }

    protected function getActionName(): string
    {
        return self::ACTION_NAME;
    }

    protected function getSkippingMessage(): string
    {
        return self::SKIPPING_MESSAGE;
    }

    protected function executeCommand(PaymentEntity $payment, PaymentSystem $paymentSystem) : void
    {
        $cancel = new Cancel(self::CANCELLATION_REASON, now());
        $this->command->handle($payment, $paymentSystem, $cancel);
    }
}
