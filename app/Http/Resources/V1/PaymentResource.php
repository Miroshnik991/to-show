<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'sum' => $this->sum,
            'payed' => $this->payed,
            'paid' => $this->paid,
            'pay_date' => $this->pay_date,
            'payload' => $this->payload,
            'payment_system' => PaymentSystemResource::make($this->whenLoaded('paymentSystem'))
        ];
    }
}
