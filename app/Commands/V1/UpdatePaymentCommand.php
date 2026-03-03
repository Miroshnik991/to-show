<?php

namespace App\Commands\V1;

use App\Dtos\V1\UpdatePaymentDto;
use App\Enums\PaymentStatusEnum;
use App\Events\PaymentCancelled;
use App\Events\PaymentFailed;
use App\Events\PaymentPayed;
use App\Events\PaymentAuthorized;
use App\Events\PaymentRefunded;
use App\Logging\InteractsWithLogger;
use App\Logging\LogCategory;
use App\Models\Payment;

class UpdatePaymentCommand
{
    use InteractsWithLogger;

    public function handle(UpdatePaymentDto $dto): Payment
    {
        /** @var Payment $payment */
        $payment = Payment::query()
            ->findOrFail($dto->guid);

        if ($dto->status !== null) {
            $payment->setStatus($dto->status);
        }

        if (!is_null($dto->ps_data)) {
            $payment->setPsData(array_merge($payment->ps_data ?? [], $dto->ps_data));
        }

        if (!is_null($dto->pay_data)) {
            $payment->pay_data = $dto->pay_data;
        }

        if ($dto->external_id) {
            $payment->external_id = $dto->external_id;
        }

        if ($dto->cancel) {
            $payment->cancel = $dto->cancel;
        }

        $logger = $this->logger();
        $logContextBuilder = $logger->createBuilder(LogCategory::PAYMENT_UPDATE)
            ->withPaymentData(\App\Entities\Payment::fromEloquent($payment));

        $logger->info('Update payment before', $logContextBuilder->build());

        try {
            $payment->save();
        } catch (\Exception $e) {
            $logger->logException($e, $logContextBuilder->build());
            throw $e;
        }

        $logger->info('Update payment after', $logContextBuilder->build());

        if ($payment->wasChanged('status')) {
            $status = $payment->status->value;
            $logger->info("Update payment after - status {$status} changed", $logContextBuilder->build());

            if ($payment->status === PaymentStatusEnum::PAYED) {
                $logger->info('before dispatch PaymentPayed', $logContextBuilder->build());
                PaymentPayed::dispatch($payment);
                $logger->info('after dispatch PaymentPayed', $logContextBuilder->build());
            }
            if ($payment->status === PaymentStatusEnum::AUTHORIZED) {
                $logger->info('before dispatch PaymentAuthorized', $logContextBuilder->build());
                PaymentAuthorized::dispatch($payment);
                $logger->info('after dispatch PaymentAuthorized', $logContextBuilder->build());
            }
            if ($payment->status === PaymentStatusEnum::FAILED) {
                $logger->info('before dispatch PaymentFailed', $logContextBuilder->build());
                PaymentFailed::dispatch($payment);
                $logger->info('after dispatch PaymentFailed', $logContextBuilder->build());
            }
            if ($payment->status === PaymentStatusEnum::REFUNDED) {
                $logger->info('before dispatch PaymentRefunded', $logContextBuilder->build());
                PaymentRefunded::dispatch($payment);
                $logger->info('after dispatch PaymentRefunded', $logContextBuilder->build());
            }
            if ($payment->status === PaymentStatusEnum::CANCELLED) {
                PaymentCancelled::dispatch($payment);
            }
        } else {
            $paymentLogData = [];
            if (method_exists($payment, 'toEloquent')) {
                $paymentLogData = $payment->toEloquent();
            } elseif (method_exists($payment, 'toArray')) {
                $paymentLogData = $payment->toArray();
            }

            $logContextBuilder->withRaw(['payment' => $paymentLogData]);
            $logger->info('Payment status wasnt changed: ' . $payment->status->value, $logContextBuilder->build());
        }

        return $payment;
    }
}
