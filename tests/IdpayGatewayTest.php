<?php

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Rohama\Koin\Support\Currency;
use Rohama\Koin\Exception\InvalidRequestException;
use Rohama\Koin\Exception\VerificationFailedException;
use Rohama\Koin\IDPay\IDPayGateway;
use Rohama\Translator\Translator;

final class IDPayGatewayTest extends TestCase
{
    public function testParseCallbackVerifiesStatus10BeforeSuccess(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 100, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $result = $gateway->parseCallback([], ['id' => 'i1', 'order_id' => 'o1', 'status' => 10, 'track_id' => '10012', 'amount' => '10000', 'card_no' => '123456******1234']);

        $this->assertSame('success', $result->status);
        $this->assertSame('i1', $result->transactionId);
        $this->assertSame('o1', $result->orderId);
        $this->assertSame('10012', $result->track_id);
        $this->assertSame('10000', $result->amount);
        $this->assertSame('123456******1234', $result->card_no);
    }

    public function testParseCallbackFailsWhenVerifyRejectsStatus10(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 1, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $result = $gateway->parseCallback([], ['id' => 'i1', 'order_id' => 'o1', 'status' => 10]);

        $this->assertSame('failed', $result->status);
    }

    public function testParseCallbackPropagatesVerifyErrors(): void
    {
        $this->expectException(VerificationFailedException::class);

        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [400, ['error_code' => 54, 'error_message' => 'مدت زمان تایید پرداخت سپری شده است.']],
        ]));

        $gateway->parseCallback([], ['id' => 'i1', 'order_id' => 'o1', 'status' => 10]);
    }

    public function testParseCallbackMapsAlreadyVerifiedToSuccess(): void
    {
        $gateway = new IDPayGateway('k');
        $result = $gateway->parseCallback([], ['id' => 'i1', 'order_id' => 'o1', 'status' => 100]);

        $this->assertSame('success', $result->status);
    }

    public function testParseCallbackMapsDepositedToSuccess(): void
    {
        $gateway = new IDPayGateway('k');
        $result = $gateway->parseCallback([], ['id' => 'i1', 'order_id' => 'o1', 'status' => 200]);

        $this->assertSame('success', $result->status);
    }

    public function testParseCallbackMapsStatus7ToCanceled(): void
    {
        $gateway = new IDPayGateway('k');
        $result = $gateway->parseCallback([], ['id' => 'i1', 'order_id' => 'o1', 'status' => 7]);

        $this->assertSame('canceled', $result->status);
    }

    public function testParseCallbackMapsOtherStatusesToFailed(): void
    {
        $gateway = new IDPayGateway('k');
        $result = $gateway->parseCallback([], ['id' => 'i1', 'order_id' => 'o1', 'status' => 1]);

        $this->assertSame('failed', $result->status);
    }

    public function testVerifyAcceptsStringAmountInRial(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 100, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000', 'payment' => ['card_no' => '123456******1234']]],
        ]));

        $result = $gateway->verify('i1', 'o1', 10000);

        $this->assertSame('success', $result->status);
        $this->assertSame('10012', $result->referenceId);
        $this->assertSame(10000, $result->amount);
    }

    public function testVerifyWithoutAmountSucceeds(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 101, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $result = $gateway->verify('i1', 'o1');

        $this->assertSame('success', $result->status);
        $this->assertSame(10000, $result->amount);
    }

    public function testVerifyConvertsTomanAmountToRial(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 100, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $result = $gateway->verify('i1', 'o1', 1000, Currency::TOMAN);

        $this->assertSame('success', $result->status);
        $this->assertSame(10000, $result->amount);
    }

    public function testVerifyAcceptsDepositedStatus(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 200, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $result = $gateway->verify('i1', 'o1');

        $this->assertSame('success', $result->status);
    }

    public function testVerifyRejectsMismatchedAmount(): void
    {
        $this->expectException(VerificationFailedException::class);

        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 100, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $gateway->verify('i1', 'o1', 9999);
    }

    public function testVerifyMapsUnpaidStatusToFailed(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 1, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $result = $gateway->verify('i1', 'o1');

        $this->assertSame('failed', $result->status);
    }

    public function testInquiryMapsStatus10ToPending(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 10, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $result = $gateway->inquiry('i1', 'o1');

        $this->assertSame('pending', $result->status);
        $this->assertSame(10000, $result->amount);
    }

    public function testInquiryMapsPaidStatusToSuccess(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [200, ['status' => 100, 'track_id' => '10012', 'id' => 'i1', 'order_id' => 'o1', 'amount' => '10000']],
        ]));

        $result = $gateway->inquiry('i1', 'o1');

        $this->assertSame('success', $result->status);
    }

    public function testRequestSendsPayerNameFromMetadata(): void
    {
        $container = [];
        $stack = new HandlerStack(new MockHandler([
            new Response(201, ['Content-Type' => 'application/json'], json_encode(['id' => 'i1', 'link' => 'https://idpay.ir/p/1'])),
        ]));
        $stack->push(Middleware::history($container));

        $gateway = new IDPayGateway('k', 'https://example.com/cb');
        $gateway->setClient(new Client(['handler' => $stack]));

        $gateway->request(10000, 'o1', metadata: ['name' => 'Ali']);

        $body = json_decode($container[0]['request']->getBody()->getContents(), true);

        $this->assertSame('Ali', $body['name']);
        $this->assertSame('o1', $body['order_id']);
        $this->assertSame(10000, $body['amount']);
    }

    public function testTransactionsReturnEmptyOnNoContent(): void
    {
        $gateway = new IDPayGateway('k');
        $gateway->setClient($this->mockHttp([
            [204, []],
        ]));

        $result = $gateway->transactions();

        $this->assertSame([], $result->records);
        $this->assertNull($result->attachment);
    }

    public function testRequestIssuesARealSandboxPayment(): void
    {
        $apiKey = getenv('IDPAY_SANDBOX_API_KEY');

        if ($apiKey === false) {
            $this->markTestSkipped('Set IDPAY_SANDBOX_API_KEY to run the live sandbox test.');
        }

        $gateway = new IDPayGateway(
            $apiKey,
            callbackUrl: 'https://example.com/cb',
            sandbox: true,
        );

        $result = $gateway->request(
            10000,
            orderId: 'koin-' . time(),
            callbackUrl: 'https://example.com/cb',
        );

        $this->assertSame('pending', $result->status);
        $this->assertNotSame('', $result->transactionId);
    }

    public function testConfigFieldsListsTheIDPayInputs(): void
    {
        $keys = array_map(fn ($f) => $f['key'], IDPayGateway::configFields());

        $this->assertSame(['apiKey', 'sandbox'], $keys);
    }

    public function testLangFilesCoverTheIDPayName(): void
    {
        $this->assertSame('IDPay', Translator::trans('Koin-IDPay:driver.name'));
    }

    public function testValidationErrorsLocalizeViaGatewayNamespace(): void
    {
        try {
            $gateway = new IDPayGateway('k');
            $gateway->request(0);
            $this->fail('Expected an InvalidRequestException.');
        } catch (InvalidRequestException $e) {
            $this->assertSame('IDPay', $e->gateway);
            $this->assertSame('مبلغ باید بزرگ‌تر از صفر باشد.', $e->message('fa'));
            $this->assertSame('Amount must be greater than zero.', $e->message());
        }
    }
}
