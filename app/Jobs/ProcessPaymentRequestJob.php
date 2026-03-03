<?php declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{SerializesModels, InteractsWithQueue};

use App\ServiceResult;
use App\Utils\PaymentSaver;
use App\Enums\PaymentStatusEnum;
use App\Exceptions\PaymentProcessException;
use App\Contracts\{PaymentHandler, PaymentContract};
use App\Logging\{LogCategory, LogContextBuilder, InteractsWithLogger};

class ProcessPaymentRequestJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, InteractsWithLogger;

    public function __construct(
        private readonly PaymentHandler $handler,
        private readonly array          $data
    )
    {}

    public function handle(): void
    {
        $payment = $this->handler->getPaymentFromData($this->data);
        if (!$payment) {
            throw new PaymentProcessException('processRequest', __('Order id not found in request data'));
        }

        $this->logger()->info(
            sprintf('Payment process request for payment %s', $payment->getId()),
            $this->createLogContextBuilder($payment)
                ->withCategory(LogCategory::PAYMENT_PROCESS_REQUEST)
                ->withRaw(['data' => $this->data])
                ->build()
        );

        $result = $this->handler->process($payment, $this->data);

        if (!$result->isSuccess()) {
            throw new PaymentProcessException('processRequest', __('Error payment handler'));
        }

        $operationType = $result->getOperationType();
        if ($psData = $result->getPsData()) {
            $payment->setPsData($psData);
        }

        $this->logger()->info(
            sprintf('Payment process result for payment %s', $payment->getId()),
            $this->createLogContextBuilder($payment)
                ->withCategory(LogCategory::PAYMENT_PROCESS_RESULT)
                ->withRaw(
                    [
                        'data' => $result->getData(),
                        'operation' => $result->getOperationType(),
                        'transaction' => $result->getTransaction()
                    ]
                )
                ->build()
        );

        if ($result->getTransaction()) {
            $payment->setExternalId($result->getTransaction());
        }

        match ($operationType) {
            ServiceResult::MONEY_COMING => $payment->setStatus(PaymentStatusEnum::PAYED),
            ServiceResult::MONEY_LEAVING => $payment->setStatus(PaymentStatusEnum::REFUNDED),
            ServiceResult::TRANSACTION_STARTED => $payment->setStatus(PaymentStatusEnum::AUTHORIZED),
            ServiceResult::PAYMENT_FAIL => $payment->setStatus(PaymentStatusEnum::FAILED),
            default => null
        };

        if ($operationType !== null) {
            PaymentSaver::save($payment);
        }
    }


    protected function createLogContextBuilder(PaymentContract $payment): LogContextBuilder
    {
        return $this->logger()->createBuilder()
            ->withPaymentData($payment)
            ->withPaymentCode($payment->getPaymentSystem()->getCode());
    }
}
