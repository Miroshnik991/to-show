<?php

declare(strict_types=1);

namespace App\Handlers;

use App\Contracts\HandlerTypes\HandlerCryptogramContract;
use App\Contracts\HandlerTypes\HandlerRefundableContract;
use App\Contracts\HandlerTypes\HandlerTransactionContract;
use App\Contracts\OrderContract;
use App\Contracts\PaymentContract;
use App\Entities\Payment;
use App\Enums\PaymentStatusEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\PlatformTypeEnum;
use App\Enums\RedirectReturnUrlEnum;
use App\Enums\SberPay\SberpayOrderStatusEnum;
use App\Enums\SberPay\SberpayPaymentStatusEnum;
use App\Enums\SberPay\SberpayResponseErrorCodeEnum;
use App\Exceptions\HandlerBadResponseException;
use App\Exceptions\PaymentGetStatusException;
use App\Exceptions\PaymentRefundException;
use App\Exceptions\PaymentTransactionCommitException;
use App\Exceptions\PaymentTransactionException;
use App\Exceptions\Sberpay\MissingPlatformException;
use App\Exceptions\Sberpay\PaymentProcessingException;
use App\Exceptions\Sberpay\SberpayBadResponseException;
use App\Logging\InteractsWithLogger;
use App\Logging\LogCategory;
use App\Logging\LogContextBuilder;
use App\Models\Payment as PaymentModel;
use App\Redirect;
use App\ServiceResult;
use App\Services\Config\PaymentConfig;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class SberPay extends Base implements HandlerCryptogramContract, HandlerTransactionContract, HandlerRefundableContract
{
    use InteractsWithLogger;

    private const int KOPECKS_TO_RUBLE = 100;


    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return __('Sber Pay');
    }

    /**
     * Process payment via cryptogram
     *
     * @param PaymentContract $payment
     * @param Request $request
     * @return ServiceResult
     * @throws PaymentProcessingException|MissingPlatformException
     */
    public function chargeViaCryptogram(PaymentContract $payment, Request $request): ServiceResult
    {
        $platform = $this->validatePlatform($request->getPlatform());
        $order = $payment->getOrder();

        try {
            $data = $this->processPaymentRegistration(
                platform: $platform,
                amount: (int)$payment->getSum(),
                orderNumber: $order->getNumber(),
                payment: $payment,
            );

            if (!$this->checkIsResponseSuccess($data['errorCode'])) {
                throw new SberpayBadResponseException($data['errorMessage']);
            }

            $this->logSuccessfulPayment($payment, $data);

            return $this->createSuccessResponse($data)->setTransaction($data['orderId']);
        } catch (SberpayBadResponseException $e) {
            $this->handleFailedSberPayment($payment, $order);
            throw new PaymentProcessingException('Payment registration failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @inheritDoc
     */
    public function process(PaymentContract $payment, array $data): ServiceResult
    {
        $data = $this->extractDataFromRequest($data);

        $actualStatusData = $this->getOrderStatus($data['order_id']);

        $result = new ServiceResult([
            'status' => 'success',
        ]);

        $status = $actualStatusData['orderStatus'] ?? null;

        return match ($status) {
            SberpayOrderStatusEnum::HOLD->value => $result
                ->setOperationType(ServiceResult::TRANSACTION_STARTED),
            SberpayOrderStatusEnum::REFUNDED->value => $result
                ->setOperationType(ServiceResult::MONEY_LEAVING),
            SberpayOrderStatusEnum::FAILED->value,
            SberpayPaymentStatusEnum::DECLINED_BY_TIMEOUT->value => $result
                ->setOperationType(ServiceResult::PAYMENT_FAIL),
            default => $result
        };
    }

    /**
     * @inheritDoc
     */
    public function getPaymentFromData(array $data): ?PaymentContract
    {
        $logContextBuilder = $this->logger()->createBuilder(LogCategory::SBERPAY_CALLBACK_REQUEST)
            ->withRaw(['request' => $data]);

        $orderNumber = $data['orderNumber'] ?? '';
        if ($orderNumber) {
            $logContextBuilder->withOrderNumber($orderNumber);
        }

        $this->logger()->info('SberPay request', $logContextBuilder->build());

        $data = $this->extractDataFromRequest($data);

        if (!empty($data['order_number'])) {
            $payment = PaymentModel::whereOrderNum($data['order_number'])
                ->where('type', PaymentTypeEnum::INCOME)
                ->where(PaymentModel::FIELD_EXTERNAL_ID, $data['order_id'])
                ->first();
            return Payment::fromEloquent($payment);
        }

        return null;
    }

    /**
     * @param PaymentContract $payment
     * @return void
     */
    public function refundPayment(PaymentContract $payment): void
    {
        try {
            $this->refund($payment);
        } catch (HandlerBadResponseException $handlerBadResponseException) {
            $body = json_decode((string) $handlerBadResponseException->response->getBody(), true);

            $message = Arr::get($body, 'reasonCode', 'Refund SberPay');
            throw (new PaymentRefundException('refund', $message))->setContext(
                $this->logger()->createBuilder(LogCategory::PAYMENT_REFUND)
                    ->withPaymentData($payment)
                    ->withRaw(['response' => $body])
                    ->build()
            );
        }
    }

    public function refund(PaymentContract $payment): void
    {
        if ($this->isIncomePayment($payment)) {
            throw new PaymentRefundException('Payment type Income is not supported for refund');
        }

        $sberOrderId = PaymentModel::find($payment->getExternalId())->{PaymentModel::FIELD_EXTERNAL_ID};
        $payload = [
            'userName' => $this->conf('username'),
            'password' => $this->conf('password'),
            'orderId' => $sberOrderId,
            'amount' => $this->convertRublesToKopecks((int)$payment->getSum()),
        ];

        $context = $this->createLogContextBuilder($payment)
            ->withCategory(LogCategory::PAYMENT_REFUND)
            ->withPaymentExternalId($sberOrderId);

        $this->logger()->info('Refund SberPay', $context->build());
        $data = $this->post($this->getUrl('refund'), $payload);
        if ($this->checkIsResponseSuccess($data['errorCode'])) {
            return;
        }

        $message = "Refund SberPay error message: {$data['errorMessage']}";
        throw (new PaymentRefundException('refund', $message))
            ->setContext($context->withRaw(['response' => $data])->build());
    }

    /**
     * @param PaymentContract $payment
     * @return void
     */
    public function commitTransaction(PaymentContract $payment): void
    {
        $payload = [
            'userName' => $this->conf('username'),
            'password' => $this->conf('password'),
            'orderId' => $payment->getExternalId(),
            'amount' => $this->convertRublesToKopecks((int)$payment->getSum()),
        ];

        $data = $this->post($this->getUrl('deposit'), $payload);

        $this->logger()->info(
            'SberPay transaction commit',
            $this->createLogContextBuilder($payment)
                ->withCategory(LogCategory::PAYMENT_TRANSACTION_COMMIT)
                ->withRaw([
                    'response' => $data,
                ])
                ->build(),
        );

        if (!$this->checkIsResponseSuccess($data['errorCode'])) {
            throw new PaymentTransactionCommitException('commit', 'Commit SberPay error message');
        }

        if ($this->getStatus($payment) !== PaymentStatusEnum::PAYED) {
            throw new PaymentTransactionCommitException('status', 'Incorrect Sber status after commit');
        };
    }

    /**
     * @param PaymentContract $payment
     * @return void
     */
    public function cancelTransaction(PaymentContract $payment): void
    {
        $payload = [
            'userName' => $this->conf('username'),
            'password' => $this->conf('password'),
            'orderId' => $payment->getExternalId(),
        ];

        $data = $this->post($this->getUrl('cancel'), $payload);

        $logContext =  $this->createLogContextBuilder($payment)
            ->withCategory(LogCategory::PAYMENT_TRANSACTION_CANCEL)
            ->withRaw([
                'response' => $data,
            ]);

        if (!$this->checkIsResponseSuccess($data['errorCode'])) {
            $this->logger()->error('SberPay transaction cancellation error', $logContext->build());
            throw new PaymentTransactionException('cancel');
        }

        $this->logger()->info('SberPay transaction cancellation', $logContext ->build());
    }

    public function sendResponse(Request $request, ?ServiceResult $result = null): Response
    {
        return new Response(status: 200);
    }

    public function handleException(\Throwable $exception, array $requestData): \Throwable
    {
        $context = $this->logger()
            ->createBuilder()
            ->withRaw($this->extractDataFromRequest($requestData))
            ->withCategory(\App\Logging\LogCategory::REQUEST_PROCESS);
        $this->logger()->logException($exception, $context->build());
        return $exception;
    }

    public function getStatus(PaymentContract $payment): ?PaymentStatusEnum
    {
        if (!$externalId = $payment->getExternalId()) {
            throw new PaymentGetStatusException('Empty external id');
        }

        $actualStatusData = $this->getOrderStatus($externalId);

        $status = $actualStatusData['orderStatus'] ?? null;

        return match ($status) {
            SberpayOrderStatusEnum::PAYED->value => PaymentStatusEnum::PAYED,
            SberpayOrderStatusEnum::HOLD->value => PaymentStatusEnum::AUTHORIZED,
            SberpayOrderStatusEnum::REFUNDED->value => PaymentStatusEnum::REFUNDED,
            SberpayOrderStatusEnum::FAILED->value,
            SberpayPaymentStatusEnum::DECLINED_BY_TIMEOUT->value => PaymentStatusEnum::FAILED,
            default => null,
        };
    }


    /**
     * Getting urls array
     */
    protected function getUrlList(): array
    {
        return [
            'register' => [
                self::ACTIVE_URL => "{$this->getProdHost()}/ecomm/gw/partner/api/v1/registerPreAuth.do",
                self::TEST_URL => "{$this->getTestHost()}/ecomm/gw/partner/api/v1/registerPreAuth.do",
            ],
            'deposit' => [
                self::ACTIVE_URL => "{$this->getProdHost()}/ecomm/gw/partner/api/v1/deposit.do",
                self::TEST_URL => "{$this->getTestHost()}/ecomm/gw/partner/api/v1/deposit.do",
            ],
            'cancel' => [
                self::ACTIVE_URL => "{$this->getProdHost()}/ecomm/gw/partner/api/v1/reverse.do",
                self::TEST_URL => "{$this->getTestHost()}/ecomm/gw/partner/api/v1/reverse.do",
            ],
            'refund' => [
                self::ACTIVE_URL => "{$this->getProdHost()}/ecomm/gw/partner/api/v1/refund.do",
                self::TEST_URL => "{$this->getTestHost()}/ecomm/gw/partner/api/v1/refund.do",
            ],
            'status' => [
                self::ACTIVE_URL => "{$this->getProdHost()}/ecomm/gw/partner/api/v1/getOrderStatusExtended.do",
                self::TEST_URL => "{$this->getTestHost()}/ecomm/gw/partner/api/v1/getOrderStatusExtended.do",
            ],
        ];
    }

    /**
     * Register pre-auth for web
     */
    protected function registerPreAuthWeb(
        int             $amount,
        string          $orderNumber,
        PaymentContract $payment,
    ): array
    {
        return $this->post($this->getUrl('register'), [
            'userName' => $this->conf('username'),
            'password' => $this->conf('password'),
            'orderNumber' => $orderNumber,
            'amount' => $this->convertRublesToKopecks($amount),
            'returnUrl' => Redirect::getReturnUrl($payment, RedirectReturnUrlEnum::SUCCESS),
        ]);
    }

    /**
     * Register pre-auth for mobile
     */
    protected function registerPreAuthMobile(
        int             $amount,
        string          $orderNumber,
        PaymentContract $payment,
    ): array
    {
        return $this->post($this->getUrl('register'), [
            'userName' => $this->conf('username'),
            'password' => $this->conf('password'),
            'orderNumber' => $orderNumber,
            'amount' => $this->convertRublesToKopecks($amount),
            'returnUrl' => Redirect::getReturnUrl($payment, RedirectReturnUrlEnum::SUCCESS),
            'jsonParams' => [
                'app2app' => true,
                'app.deepLink' => self::APP_DEEP_LINK,
            ],
        ]);
    }


    private function getOrderStatus(string $sberOrderId): array
    {
        $requestStatusData = [
            'userName' => $this->conf('username'),
            'password' => $this->conf('password'),
            'orderId' => $sberOrderId,
        ];

        $statusData = $this->post($this->getUrl('status'), $requestStatusData);

        $logContextBuilder = $this->logger()->createBuilder()
            ->withCategory(LogCategory::PAYMENT_STATUS)
            ->withPaymentExternalId($sberOrderId)
            ->withRaw([
                'response' => $statusData,
            ]);
        $this->logger()->info('Sber order status data', $logContextBuilder->build());

        return $statusData;
    }

    protected function extractDataFromRequest(array $data): array
    {
        return [
            'order_id' => $data['mdOrder'],
            'order_number' => $data['orderNumber'],
            'operation_type' => $data['operation'],
            'status' => $data['status'],
        ];
    }

    /**
     * Validate platform parameter
     * @throws MissingPlatformException
     */
    private function validatePlatform(?PlatformTypeEnum $platform): string
    {
        if (empty($platform)) {
            throw new MissingPlatformException('Platform query parameter is required');
        }

        if (!PlatformTypeEnum::tryFrom($platform->value)) {
            throw new MissingPlatformException('Invalid platform specified. Must be "web" or "mobile"');
        }

        return $platform->value;
    }

    /**
     * Process payment registration based on platform
     */
    private function processPaymentRegistration(
        string          $platform,
        int             $amount,
        string          $orderNumber,
        PaymentContract $payment,
    ): array
    {
        return $platform === PlatformTypeEnum::WEB->value
            ? $this->registerPreAuthWeb($amount, $orderNumber, $payment)
            : $this->registerPreAuthMobile($amount, $orderNumber, $payment);
    }

    /**
     * Log successful payment attempt
     */
    private function logSuccessfulPayment(
        PaymentContract $payment,
        array           $responseData
    ): void
    {
        $this->logger()->info(
            'Successful Payment Two-Factor Registration',
            $this->createLogContextBuilder($payment)
                ->withCategory(LogCategory::PAYMENT_CHARGE_CRYPTOGRAM)
                ->withRaw([
                    'response' => $responseData,
                ])
                ->build()
        );
    }

    /**
     * Log failed payment attempt
     */
    private function logFailedPayment(PaymentContract $payment, OrderContract $order): void
    {
        $this->logger()->info(
            'Failed Successful Payment Two-Factor Registration',
            $this->createLogContextBuilder($payment)
                ->withCategory(LogCategory::PAYMENT_CHARGE_CRYPTOGRAM)
                ->build()
        );
    }

    /**
     * Create success response
     */
    private function createSuccessResponse(array $paymentData): ServiceResult
    {
        return new ServiceResult([
            'api_key' => $this->conf('api_key'),
            'url' => $paymentData['formUrl'],
            'order_id' => $paymentData['orderId'],
            'expiration_date' => now()->addSeconds(PaymentConfig::getConfirmationTimeoutInSeconds())->format('Y-m-d H:i:s'),
            'life_time' => PaymentConfig::getConfirmationTimeoutInSeconds(),
            'is_finish_page' => $this->conf('is_finish_page'),
            'external_params' => [
                'sbol_deep_link' => $deepLink = $paymentData['externalParams']['sbolDeepLink'] ?? null,
                'sbol_bank_invoice_id' => $this->getBankInvoiceId($deepLink),
            ],
        ]);
    }

    private function getBankInvoiceId(string $sbolDeepLink): ?string
    {
        parse_str(parse_url($sbolDeepLink, PHP_URL_QUERY) ?? '', $params);

        $bankInvoiceId = $params['bankInvoiceId'] ?? null;

        return $bankInvoiceId && preg_match('/^[a-f0-9]{32}$/', $bankInvoiceId)
            ? $bankInvoiceId
            : null;
    }

    private function handleFailedSberPayment(PaymentContract $payment, OrderContract $order): void
    {
        $this->logFailedPayment($payment, $order);
        $payment->setStatus(PaymentStatusEnum::FAILED);
    }

    private function checkIsResponseSuccess(string $errorCode): bool
    {
        return $errorCode == SberpayResponseErrorCodeEnum::NO_ERRORS->value;
    }

    private function getProdHost(): string
    {
        return $this->conf('prod_host');
    }

    private function getTestHost(): string
    {
        return $this->conf('test_host');
    }

    private function convertRublesToKopecks(int $rubles): int
    {
        return $rubles * self::KOPECKS_TO_RUBLE;
    }

    private function isIncomePayment(Payment $payment): bool
    {
        return $payment->getType() === PaymentTypeEnum::INCOME;
    }

    private function createLogContextBuilder(PaymentContract $payment): LogContextBuilder
    {
        return $this->logger()->createBuilder()
            ->withPaymentData($payment)
            ->withPaymentCode($payment->getPaymentSystem()->getCode());
    }
}
