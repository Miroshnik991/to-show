<?php

namespace App;

use App\Contracts\HandlerTypes\HandlerLinkContract;
use App\Contracts\HandlerTypes\HandlerCryptogramContract;
use App\Contracts\HandlerTypes\HandlerRefundableContract;
use App\Contracts\HandlerTypes\HandlerTokenContract;
use App\Contracts\HandlerTypes\HandlerTransactionContract;
use App\Contracts\HandlerTypes\HandlerQRContract;
use App\Enums\PaymentStatusEnum;
use App\Enums\PayTypeEnum;
use App\Exceptions\PaymentCancelException;
use App\Exceptions\PaymentNotSupportedException;
use App\Exceptions\PaymentRefundException;
use App\Exceptions\PaymentTransactionCommitException;
use App\Exceptions\PaymentTransactionException;
use App\Exceptions\PaymentTransactionRollbackException;
use App\Exceptions\UnsupportedPaymentTypeException;
use App\Jobs\ProcessPaymentRequestJob;
use App\Logging\InteractsWithLogger;
use App\Logging\LogCategory;
use App\Models\PaymentSystem;
use App\Contracts\PaymentContract;
use App\Contracts\PaymentHandler;
use App\Contracts\PaymentService;
use App\Utils\PaymentSaver;
use App\ValueObjects\Cancel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class Service implements PaymentService
{
    use InteractsWithLogger;

    protected function __construct(
        protected PaymentHandler $handler
    ) {
    }

    public static function create(PaymentSystem $paymentSystem): static
    {
        $handler = $paymentSystem->handler_class;
        if (empty($handler) || !class_exists($handler)) {
            throw new \LogicException(sprintf('Payment handler %s not found', $handler));
        }

        $handler = new $handler($paymentSystem);

        if (!$handler instanceof PaymentHandler) {
            throw new \LogicException(sprintf('Payment handler should be instanceof PaymentHandler, % given', $handler));
        }

        return new static($handler);
    }

    public function getConfig(): array
    {
        return $this->handler->getConfig();
    }

    public function initiatePay(PayTypeEnum $type, PaymentContract $payment, Request $request)
    {
        if ($data = $payment->getPayData($type)) {
            return $data;
        }

        $result = match ($type) {
            PayTypeEnum::CRYPTO => $this->handler instanceof HandlerCryptogramContract
                ? $this->handler->chargeViaCryptogram($payment, $request)
                : throw new UnsupportedPaymentTypeException($this->handler, $type),

            PayTypeEnum::LINK => $this->handler instanceof HandlerLinkContract
                ? $this->handler->chargeViaLink($payment, $request)
                : throw new UnsupportedPaymentTypeException($this->handler, $type),

            PayTypeEnum::TOKEN => $this->handler instanceof HandlerTokenContract
                ? $this->handler->chargeViaToken($payment, $request)
                : throw new UnsupportedPaymentTypeException($this->handler, $type),

            PayTypeEnum::QR => $this->handler instanceof HandlerQRContract
                ? $this->handler->chargeViaQR($payment, $request)
                : throw new UnsupportedPaymentTypeException($this->handler, $type),
        };

        if ($result->getTransaction()) {
            $payment->setExternalId($result->getTransaction());
        }

        $payment->setPayData($type, $result->getData());

        PaymentSaver::save($payment);

        return $result->getData();
    }

    public function processRequest(Request $request, PaymentContract $payment = null): Response
    {
        $requestData = $request->all();
        try {
            ProcessPaymentRequestJob::dispatch(
                $this->handler,
                $requestData
            );
            return $this->handler->sendResponse($request);
        } catch (\Throwable $e) {
            throw $this->handler->handleException($e, $requestData);
        }
    }

    /**
     * @param PaymentContract $payment
     *
     * @return PaymentContract
     * @throws PaymentTransactionCommitException|PaymentNotSupportedException|\Throwable
     */
    public function commitTransaction(PaymentContract $payment): PaymentContract
    {
        if (!$this->handler instanceof HandlerTransactionContract) {
            throw new PaymentNotSupportedException();
        }

        if (empty($payment->getExternalId())) {
            throw new PaymentTransactionCommitException('Transaction for payment #%s are not open', $payment->getId());
        }

        try {
            $this->handler->commitTransaction($payment);
            $payment->setStatus(PaymentStatusEnum::PAYED);
            PaymentSaver::save($payment);
        } catch (PaymentTransactionException $exception) {
            $context = $exception->getContext();
            if (!empty($context)) {
                $this->logger()->logException($exception, $context);
            } else {
                $this->logger()->logException(
                    $exception,
                    $this->logger()->createBuilder(LogCategory::PAYMENT_TRANSACTION_COMMIT)
                        ->withPaymentData($payment)
                        ->build()
                );
            }

            throw new PaymentTransactionCommitException($exception->getMessage());
        }

        return $payment;
    }

    /**
     * @param PaymentContract $payment
     *
     * @return PaymentContract
     * @throws PaymentTransactionRollbackException|PaymentNotSupportedException
     */
    public function cancelTransaction(PaymentContract $payment, ?Cancel $cancel = null): PaymentContract
    {
        if (!$this->handler instanceof HandlerTransactionContract) {
            throw new PaymentNotSupportedException();
        }

        try {
            $this->handler->cancelTransaction($payment);
            $payment->setStatus(PaymentStatusEnum::CANCELLED);
            $payment->setCancel($cancel);
            PaymentSaver::save($payment);
        } catch (PaymentTransactionException $exception) {
            $context = $exception->getContext();
            if (!empty($context)) {
                $this->logger()->logException($exception, $context);
            } else {
                $this->logger()->logException(
                    $exception,
                    $this->logger()->createBuilder(LogCategory::PAYMENT_TRANSACTION_CANCEL)
                        ->withPaymentData($payment)
                        ->build()
                );
            }

            throw new PaymentTransactionRollbackException($exception->getMessage());
        }

        return $payment;
    }

    public function refund(PaymentContract $payment): PaymentContract
    {
        return $this->makeRefundOrCancel($payment);
    }

    public function cancel(PaymentContract $payment, ?Cancel $cancel = null): PaymentContract
    {
        $status = $this->handler->getStatus($payment);
        if (is_null($status)) {
            throw new PaymentCancelException('cancel', __('Cannot make cancel - Payment status is null.'));
        }

        return match ($status) {
            PaymentStatusEnum::AUTHORIZED => $this->cancelTransaction($payment, $cancel),
            PaymentStatusEnum::PAYED => $this->makeRefundOrCancel($payment, $cancel, true),
            default => throw new PaymentCancelException(
                'cancel',
                __(
                    'You cannot make cancel from status (:status) allowed statuses (:allowed_statuses)',
                    ['status' => $status->name, 'allowed_statuses' => implode(',', [
                        PaymentStatusEnum::AUTHORIZED->name,
                        PaymentStatusEnum::PAYED->name
                    ])]
                )
            )
        };
    }

    protected function makeRefundOrCancel(
        PaymentContract $payment,
        ?Cancel $cancel = null,
        bool $isCancel = false
    ): PaymentContract {
        if (!$this->handler instanceof HandlerRefundableContract) {
            throw new PaymentNotSupportedException();
        }

        try {
            $this->handler->refundPayment($payment);
            $payment->setCancel($cancel);
            $payment->setStatus($isCancel ? PaymentStatusEnum::CANCELLED : PaymentStatusEnum::REFUNDED);
            PaymentSaver::save($payment);
        } catch (PaymentRefundException $exception) {
            $context = $exception->getContext();
            if (!empty($context)) {
                $this->logger()->logException($exception, $context);
            } else {
                $this->logger()->logException(
                    $exception,
                    $this->logger()->createBuilder()
                        ->withCategory(LogCategory::PAYMENT_REFUND)
                        ->withPaymentData($payment)
                        ->build()
                );
            }
        }

        return $payment;
    }
}
