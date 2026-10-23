# Koin — IDPay

<p align="center">
  <a href="https://rohamaa.github.io/Koin-Core/">Docs site</a> ·
  <a href="https://github.com/rohamaa/Koin-Core/blob/docs/index.md">Docs on GitHub</a>
</p>

The **IDPay** gateway driver for the Koin package — built on IDPay's REST API v1.1.

### Install

```bash
composer require rohama/koin-idpay
```

### Features

- Payment request — returns id + StartPay redirect URL
- Verify
- Inquiry
- Transaction list
- Callback parsing
- Sandbox



### Configuration

| Field | Description |
| --- | --- |
| `apiKey` | 36-char API key |
| `sandbox` | IDPay's official test mode |

### Usage

```php
use Rohama\Koin\IDPay\IDPayGateway;
use Rohama\Koin\Support\PaymentStatus;

$gateway = new IDPayGateway('api-key-36-chars', 'https://example.com/callback');

$payment = $gateway->request(150000, 'ORDER-1', 'Subscription purchase');

if ($payment->isSuccessful()) {
    header('Location: ' . $payment->redirectUrl);
    exit;
}

// In the callback page — parseCallback runs verify() before reporting success
$callback = $gateway->parseCallback($_GET, $_POST);
if ($callback->status === PaymentStatus::SUCCESS) {
    // Verified
}
```

> `refund()` (in gateways that offer Refund) is available only when the developer-only 5th constructor parameter (`refundable`) is enabled.

Full documentation: <https://rohamaa.github.io/Koin-Core/>