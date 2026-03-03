<?php

declare(strict_types=1);

namespace App\Commands\V1;

use App\Entities\Payment;
use App\Models\PaymentSystem;
use App\Service;
use App\ValueObjects\Cancel;

class CancelPaymentCommand
{
    public function handle(
        Payment $refundPayment,
        PaymentSystem $paymentSystem,
        ?Cancel $cancel = null
    ): void {
        $service = Service::create($paymentSystem);
        $service->cancel($refundPayment, $cancel);
    }
}
