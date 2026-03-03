<?php

namespace App\Handlers;

use App\Contracts\PaymentContract;
use App\Contracts\PaymentHandler;
use App\Exceptions\HandlerBadResponseException;
use App\Logging\InteractsWithLogger;
use App\Models\PaymentSystem;
use App\ServiceResult;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\RequestOptions;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;

abstract class Base implements PaymentHandler
{
    use InteractsWithLogger;

    const string TEST_URL = 'test';
    const string ACTIVE_URL = 'active';

    protected PaymentSystem $ps;
    /** @var HttpClient */
    protected $client;
    /** @var string[] */
    protected array $defaultHeaders = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];

    public function __construct(PaymentSystem $paymentSystem)
    {
        $this->ps = $paymentSystem;
    }

    public function getCurrencies(): array
    {
        return [config('sale.base_currency', 'RUB')];
    }

    public function getConfig(): array
    {
        return [];
    }

    public function initiatePay(PaymentContract $payment, Request $request): ServiceResult
    {
        return new ServiceResult();
    }

    /**
     * @param ServiceResult $result
     * @param \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     */
    public function sendResponse(Request $request, ?ServiceResult $result = null): Response
    {
        return new Response('', 204);
    }

    public function handleException(\Throwable $exception, array $requestData): \Throwable
    {
        $context = $this->logger()
            ->createBuilder()
            ->withRaw($requestData)
            ->withCategory(\App\Logging\LogCategory::REQUEST_PROCESS);
        $this->logger()->logException($exception, $context->build());
        return $exception;
    }

    protected function formatPrice($price): float
    {
        return number_format($price, 2, '.', '');
    }

    /**
     * @param string $action
     *
     * @return string
     */
    protected function getUrl(string $action): string
    {
        if (str_starts_with($action, 'http')) {
            return $action;
        }
        $urlList = $this->getUrlList();
        if (isset($urlList[$action])) {
            $url = $urlList[$action];

            if (is_array($url)) {
                if ($this->isTestMode() && isset($url[self::TEST_URL])) {
                    return $url[self::TEST_URL];
                } else {
                    return $url[self::ACTIVE_URL];
                }
            } else {
                return $url;
            }
        }

        return '';
    }

    /**
     * @param string $name
     * @param mixed  $default
     *
     * @return mixed
     */
    protected function conf(string $name, string|array $default = null): mixed
    {
        $value = Arr::get($this->ps->params, $name, $default);

        return config('systems.' . $this->ps->id . '.config.' . mb_strtolower($name), $value);
    }

    /**
     * @return bool
     */
    protected function isTestMode(): bool
    {
        return $this->conf('test', false);
    }

    /**
     * @return array
     */
    protected function getUrlList(): array
    {
        return [];
    }

    /**
     * @return \GuzzleHttp\Client
     */
    protected function getClient(): HttpClient
    {
        if (!$this->client) {
            $this->client = new HttpClient();
        }

        return $this->client;
    }

    protected function makeRequestHeaders(): array
    {
        return $this->defaultHeaders;
    }

    protected function get(string $action, array $data = [], array $options = [])
    {
        try {
            $r = $this->getClient()->get(
                $this->getUrl($action), array_merge([
                    RequestOptions::VERIFY => true,
                    RequestOptions::HEADERS => $this->makeRequestHeaders(),
                    RequestOptions::QUERY => $data,
                ], $options)
            );

            return json_decode($r->getBody(), true);
        } catch (BadResponseException $e) {
            throw new HandlerBadResponseException($e->getResponse());
        }
    }

    protected function post(string $action, array $data, array $options = [])
    {
        try {
            $r = $this->getClient()->post(
                $this->getUrl($action), array_merge([
                    RequestOptions::VERIFY => true,
                    RequestOptions::HEADERS => $this->makeRequestHeaders(),
                    RequestOptions::JSON => $data,
                ], $options)
            );

            return json_decode($r->getBody(), true);
        } catch (BadResponseException $e) {
            throw new HandlerBadResponseException($e->getResponse());
        }
    }
}
