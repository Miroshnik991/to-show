<?php

namespace App\Console\Commands;

use App\Commands\V1\UpdatePaymentCommand;
use App\Enums\PaymentStatusEnum;
use App\Enums\PaymentTypeEnum;
use App\Logging\InteractsWithLogger;
use App\Logging\LogCategory;
use App\Models\Payment;
use App\Service;
use App\ValueObjects\Cancel;
use Illuminate\Console\Command;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Operation\FindOneAndUpdate;
use Illuminate\Support\Carbon;

class CancelExpiredPayments extends Command
{
    use InteractsWithLogger;

    protected $signature = 'app:cancel-expired-payments';
    protected $description = 'Cancel payment with expired ttl';

    public function __construct(private readonly UpdatePaymentCommand $updatePaymentCommand)
    {
        parent::__construct();
    }

    private function getNextPayment(): ?Payment
    {
        return Payment::query()->raw(function (Collection $collection) {
            return $collection->findOneAndUpdate(
                [
                    'type' => PaymentTypeEnum::INCOME,
                    'status' => ['$in' => [PaymentStatusEnum::NEW, PaymentStatusEnum::AUTHORIZED]],
                    '$expr' => [
                        '$lt' => [
                            ['$add' => ['$created_at', ['$multiply' => ['$ttl', 1000]]]],
                            new UTCDateTime(now()->getTimestampMs()),
                        ],
                    ],
                ],
                [
                    '$set' => [
                        'status' => PaymentStatusEnum::PROCESSING,
                    ],
                ],
                [
                    'returnDocument' => FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
                    'typeMap' => ['document' => 'array']
                ]
            );
        });
    }

    public function handle(): int
    {
        while ($payment = $this->getNextPayment()) {
            $contextBuilder = $this->logger()->createBuilder(LogCategory::CLI_CANCEL_EXPIRED_PAYMENTS)
                ->withPaymentId($payment->id)
                ->withPaymentStatus($payment->status->value)
                ->withOrderNumber($payment->order->number);

            try {
                $paymentContract = \App\Entities\Payment::fromEloquent($payment);
                $service = Service::create($payment->paymentSystem);

                $this->logger()->info('found payment to cancel by expiration');

                $cancel = new Cancel('Cancel due timeout', Carbon::now());
                $service->cancel($paymentContract, $cancel);
            } catch (\Exception $exception) {
                $contextBuilder->withRaw(['payment_id' => $payment->id]);
                $this->logger()->logException($exception, $contextBuilder->build(), 'Cancel transaction by time out');
            }
        }

        return Command::SUCCESS;
    }
}
