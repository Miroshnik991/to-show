<?php

namespace App\Http\Controllers\V1;

use App\Entities\Payment as PaymentEntity;
use App\Enums\PayTypeEnum;
use App\Exceptions\PaymentProcessException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PaymentSystem;
use App\Service;
use Illuminate\Http\Request;

class PayProcessController extends Controller
{
    protected function resolveSystem(string $code): PaymentSystem
    {
        foreach (config('systems') as $id => $system) {
            if ($system['code'] === $code) {
                return PaymentSystem::query()
                    ->findOrFail($id);
            }
        }
        throw new PaymentProcessException('resolveSystem', "{$code} not configured");
    }

    protected function initiatePay(PayTypeEnum $type, Payment $payment, Request $request)
    {
        $entity = PaymentEntity::fromEloquent($payment);

        return Service::create($payment->paymentSystem)
            ->initiatePay($type, $entity, $request);
    }

    public function pay(Payment $payment, Request $request)
    {
        return $this->initiatePay(PayTypeEnum::CRYPTO, $payment, $request);
    }
}
