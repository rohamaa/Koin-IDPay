<?php

namespace Rohama\Koin\IDPay;

use GuzzleHttp\Client;
use Rohama\Koin\Support\Currency;
use Rohama\Koin\Support\PaymentStatus;
use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\PaymentFailedException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\InquiryGateway;
use Rohama\Koin\PaymentGateway;
use Rohama\Koin\Requests\Callback;
use Rohama\Koin\Responses\PaymentResponse;
use Rohama\Koin\Responses\TransactionsResponse;
use Rohama\Koin\Responses\VerificationResponse;
use Rohama\Koin\SandboxGateway;
use Rohama\Koin\Traits\IsPayment;
use Rohama\Koin\TransactionListGateway;
use Rohama\Translator\Translator;

/**
 * IDPay gateway — REST API v1.1.
 */
class IDPayGateway implements PaymentGateway, InquiryGateway, TransactionListGateway, SandboxGateway
{
    use IsPayment { __construct as protected paymentConstruct; }

    public const name = 'IDPay';

    private const MIN_AMOUNT = 1000;
    private const MAX_AMOUNT = 500_000_000;

    private const STATUS_PAID = 100;
    private const STATUS_AWAITING_VERIFICATION = 10;
    private const STATUS_CANCELED = 7;

    private const STATUS_MESSAGES = [
        1 => 'not paid',
        2 => 'payment failed',
        3 => 'error',
        4 => 'blocked',
        5 => 'refunded to payer',
        6 => 'system refund',
        7 => 'canceled by payer',
        8 => 'sent to gateway',
        10 => 'awaiting verification',
        100 => 'paid',
        101 => 'already verified',
        200 => 'deposited to recipient',
    ];

    private function isPaid(int $status): bool
    {
        return $status >= self::STATUS_PAID;
    }

    public function __construct(string $apiKey, ?string $callbackUrl = null, bool $sandbox = false, array $extra = [], bool $refundable = false)
    {
        $this->paymentConstruct($apiKey, $callbackUrl, $sandbox, $extra, $refundable);

        Translator::register('Koin-IDPay', dirname(__DIR__) . '/lang');
        $this->client = new Client([
            'base_uri' => 'https://api.idpay.ir/v1.1',
            'timeout' => 15,
            'connect_timeout' => 10,
            'http_errors' => false,
            'headers' => [
                'X-API-KEY' => $this->apiKey,
                'X-SANDBOX' => $this->sandbox ? 'true' : 'false',
            ],
        ]);
    }

    public static function configFields(): array
    {
        return [
            [
                'key' => 'apiKey',
                'name' => Translator::trans('Koin-IDPay:fields.apiKey'),
                'desc' => Translator::trans('Koin-IDPay:fields.apiKey_help'),
                'type' => 'text',
                'required' => true,
                'validation' => ['required', 'string', 'size:36'],
                'regex' => '/^.{36}$/',
            ],
            [
                'key' => 'sandbox',
                'name' => Translator::trans('Koin-IDPay:fields.sandbox'),
                'desc' => Translator::trans('Koin-IDPay:fields.sandbox_help'),
                'type' => 'checkbox',
                'required' => false,
                'validation' => ['boolean'],
                'regex' => null,
            ],
        ];
    }

    public function request(
        int $amount,
        string $orderId = '',
        ?string $callbackUrl = null,
        string $description = '',
        ?string $mobile = null,
        ?string $email = null,
        ?string $currency = null,
        array $metadata = [],
        array $extra = [],
    ): PaymentResponse {
        if ($amount <= 0) {
            throw new InvalidRequestException('Amount must be greater than zero.', gateway: self::name);
        }

        if ($orderId === '') {
            throw new InvalidRequestException('IDPay requires an order id.', gateway: self::name);
        }

        if ($callbackUrl === null && $this->callbackUrl === null) {
            throw new InvalidRequestException('IDPay requires a callback URL.', gateway: self::name);
        }

        $rial = Currency::toRial($currency ?? Currency::RIAL, $amount);

        if ($rial < self::MIN_AMOUNT) {
            throw new InvalidRequestException('IDPay requires an amount of at least 1,000 Rial.', gateway: self::name);
        }

        if ($rial > self::MAX_AMOUNT) {
            throw new InvalidRequestException('IDPay amount exceeds the 500,000,000 Rial maximum.', gateway: self::name);
        }

        $response = $this->client()->post('/payment', [
            'json' => array_filter(array_merge([
                'order_id' => $orderId,
                'amount' => $rial,
                'name' => $metadata['name'] ?? null,
                'callback' => $callbackUrl ?? $this->callbackUrl,
                'phone' => $mobile,
                'mail' => $email,
                'desc' => $description,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() !== 201 || !isset($data['id'])) {
            throw new PaymentFailedException($this->IDPayError($data), $response->getStatusCode(), raw: $data, gateway: self::name);
        }

        return new PaymentResponse(
            $data,
            $response,
            PaymentStatus::PENDING,
            (string) $data['id'],
            isset($data['link']) ? (string) $data['link'] : null,
            $orderId,
            null,
        );
    }

    public function verify(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): VerificationResponse {
        if ($orderId === '') {
            throw new InvalidRequestException('IDPay requires an order id.', gateway: self::name);
        }

        $response = $this->client()->post('/payment/verify', [
            'json' => array_filter(array_merge([
                'id' => $transactionId,
                'order_id' => $orderId,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() !== 200) {
            throw new VerificationFailedException($this->IDPayError($data), $response->getStatusCode(), raw: $data, gateway: self::name);
        }

        $status = (int) ($data['status'] ?? -1);

        if (!$this->isPaid($status)) {
            return new VerificationResponse($data, $response, PaymentStatus::FAILED, $transactionId, null, null, null, $this->statusMessage($status));
        }

        $expect = null;

        if ($amount !== null) {
            $expect = Currency::toRial($currency ?? Currency::RIAL, $amount);

            if ($data['amount'] != $expect) {
                throw new VerificationFailedException(
                    'IDPay verified amount mismatch.',
                    0,
                    raw: ['expected' => $expect, 'actual' => $data['amount']],
                    gateway: self::name,
                );
            }
        }

        $expect ??= isset($data['amount']) ? (int) $data['amount'] : null;

        return new VerificationResponse(
            $data,
            $response,
            PaymentStatus::SUCCESS,
            $transactionId,
            isset($data['track_id']) ? (string) $data['track_id'] : null,
            $expect,
            isset($data['payment']['card_no']) ? (string) $data['payment']['card_no'] : null,
            null,
        );
    }

    public function parseCallback(array $query, array $body): Callback
    {
        $raw = array_merge($query, $body);
        $id = isset($raw['id']) ? (string) $raw['id'] : '';
        $orderId = isset($raw['order_id']) ? (string) $raw['order_id'] : '';
        $status = (int) ($raw['status'] ?? -1);

        if ($id === '' || $orderId === '') {
            throw new InvalidRequestException('IDPay invalid Callback payload.', gateway: self::name);
        }

        $extra = $raw;

        if ($this->isPaid($status)) {
            return new Callback(PaymentStatus::SUCCESS, $id, $orderId, $extra);
        }

        if ($status !== self::STATUS_AWAITING_VERIFICATION) {
            $result = $status === self::STATUS_CANCELED ? PaymentStatus::CANCELED : PaymentStatus::FAILED;

            return new Callback($result, $id, $orderId, $extra);
        }

        $verified = $this->verify($id, $orderId);

        return new Callback($verified->status, $id, $orderId, $extra);
    }

    public function inquiry(
        string $transactionId,
        string $orderId = '',
        ?int $amount = null,
        ?string $currency = null,
        array $extra = [],
    ): VerificationResponse {
        if ($orderId === '') {
            throw new InvalidRequestException('IDPay requires the order id for inquiry.', gateway: self::name);
        }

        $response = $this->client()->post('/payment/inquiry', [
            'json' => array_filter(array_merge([
                'id' => $transactionId,
                'order_id' => $orderId,
            ], $extra), fn ($value) => $value !== null && $value !== ''),
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() !== 200) {
            throw new VerificationFailedException($this->IDPayError($data), $response->getStatusCode(), raw: $data, gateway: self::name);
        }

        $status = (int) ($data['status'] ?? -1);

        if ($this->isPaid($status)) {
            return new VerificationResponse(
                $data,
                $response,
                PaymentStatus::SUCCESS,
                $transactionId,
                isset($data['track_id']) ? (string) $data['track_id'] : null,
                isset($data['amount']) ? (int) $data['amount'] : null,
                isset($data['payment']['card_no']) ? (string) $data['payment']['card_no'] : null,
                null,
            );
        }

        if ($status === self::STATUS_AWAITING_VERIFICATION) {
            return new VerificationResponse(
                $data,
                $response,
                PaymentStatus::PENDING,
                $transactionId,
                isset($data['track_id']) ? (string) $data['track_id'] : null,
                isset($data['amount']) ? (int) $data['amount'] : null,
                isset($data['payment']['card_no']) ? (string) $data['payment']['card_no'] : null,
                $this->statusMessage($status),
            );
        }

        return new VerificationResponse(
            $data,
            $response,
            PaymentStatus::FAILED,
            $transactionId,
            null,
            null,
            null,
            $this->statusMessage($status),
        );
    }

    public function transactions(int $page = 0, int $pageSize = 25, array $filters = []): TransactionsResponse
    {
        $response = $this->client()->post('/payment/transactions?' . http_build_query([
            'page' => $page,
            'page_size' => $pageSize,
        ]), [
            'json' => $filters,
        ]);
        $data = json_decode($response->getBody()->getContents(), true);

        if ($response->getStatusCode() === 204) {
            return new TransactionsResponse([], $response, [], null);
        }

        if ($response->getStatusCode() !== 200) {
            throw new PaymentFailedException($this->IDPayError($data), $response->getStatusCode(), raw: $data, gateway: self::name);
        }

        return new TransactionsResponse(
            $data,
            $response,
            isset($data['records']) && is_array($data['records']) ? $data['records'] : [],
            isset($data['attachment']) && is_array($data['attachment']) ? $data['attachment'] : null,
        );
    }

    /**
     * Builds a status message for a transaction status.
     */
    private function statusMessage(int $status): string
    {
        $label = self::STATUS_MESSAGES[$status] ?? 'unknown';
        return sprintf('IDPay transaction status %d (%s).', $status, $label);
    }

    /**
     * Builds an error message from an IDPay failure payload.
     */
    private function IDPayError(array $data): string
    {
        $message = isset($data['error_message']) ? (string) $data['error_message'] : 'Unknown error';
        $code = isset($data['error_code']) ? (int) $data['error_code'] : null;
        return $code !== null
            ? sprintf('IDPay error (code %d): %s', $code, $message)
            : $message;
    }
}
