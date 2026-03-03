<?php

namespace App\Commands\V1;

use App\Dtos\V1\CreatePaymentDto;
use App\Events\PaymentCreated;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;

class CreatePaymentCommand
{
    public function handle(CreatePaymentDto $dto): Payment
    {
        /** @var Payment $payment */
        $payment = Payment::query()
            ->create($dto->toEloquent());

        /** @var Order $order */
        $order = $payment->order()
            ->create($dto->order->toEloquent());

        /** @var Cart $cart */
        $cart = $order->cart()
            ->create($dto->order->getCart()->toEloquent());

        $order->customer()->create($dto->order->getCustomer()->toEloquent());

        foreach ($this->getItems($dto->order->getCart()) as $item) {
            $cart->items()
                ->create($item->toEloquent());
        }

        PaymentCreated::dispatch($payment);

        return $payment;
    }

    /**
     * @return \App\Contracts\CartItemContract[]
     */
    protected function getItems(\App\Contracts\CartContract $cart): array
    {
        /** @var \App\Contracts\CartItemContract[] $preparedItems */
        $preparedItems = [];

        foreach ($cart->getItems() as $item) {
            $currentItem = $preparedItems[$item->getSkuId()] ?? null;
            if (!$currentItem) {
                $preparedItems[$item->getSkuId()] = $item;
                continue;
            }

            $currentItem->setQuantity($currentItem->getQuantity() + $item->getQuantity());
        }

        return $preparedItems;
    }
}
