<?php

namespace App\Listeners;

use App\Entities\Payment;
use App\Enums\PaymentStatusEnum;
use App\Events\PaymentAuthorized;
use App\Exceptions\PaymentTransactionCommitException;
use App\Exceptions\PaymentTransactionRollbackException;
use App\Models\Payment as PaymentModel;
use App\Service;
use Illuminate\Support\Collection;

class CommitPayments
{
    public function handle(PaymentAuthorized $event): void
    {
        $total = PaymentModel::query()
            ->whereOrder($event->payment->getOrder())
            ->count();

        $payments = PaymentModel::query()
            ->whereOrder($event->payment->getOrder())
            ->whereStatus(PaymentStatusEnum::AUTHORIZED)
            ->get();

        if ($payments->isNotEmpty()
            && // All payments transactions open already, so, close it
            ($payments->count() === $total)) {
            $this->commitOrRollback($payments);
        }
    }

    protected function commitOrRollback(Collection $payments): void
    {
        try {
            $payments->each(function (PaymentModel $payment) {
                Service::create($payment->paymentSystem)
                    ->commitTransaction(Payment::fromEloquent($payment));
            });
        } catch (PaymentTransactionCommitException $e) {
            $payments->each(function (PaymentModel $payment) {
                try {
                    Service::create($payment->paymentSystem)
                        ->cancelTransaction(Payment::fromEloquent($payment));
                } catch (PaymentTransactionRollbackException $e) {
                }
            });
        }
    }
}
