<?php

namespace App\Commands\V1;

use App\Entities\Payment;
use App\Models\PaymentSystem;
use App\Service;

class RefundPaymentCommand
{
    public function handle(
        Payment $refundPayment,
        PaymentSystem $paymentSystem
    ): void {
        $service = Service::create($paymentSystem);
        $service->refund($refundPayment);
    }
}
