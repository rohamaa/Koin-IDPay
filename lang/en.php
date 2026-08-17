<?php

return [
    'driver.name' => 'IDPay',
    'fields.apiKey' => 'API Key',
    'fields.apiKey_help' => 'Your IDPay API key, sent as the X-API-KEY header.',
    'fields.sandbox' => 'Sandbox mode',
    'fields.sandbox_help' => 'Use the IDPay sandbox/test environment instead of the live one.',
    'messages.Amount must be greater than zero.' => 'Amount must be greater than zero.',
    'messages.IDPay requires an order id.' => 'IDPay requires an order id.',
    'messages.IDPay requires a callback URL.' => 'IDPay requires a callback URL.',
    'messages.IDPay requires an amount of at least 1,000 Rial.' => 'IDPay requires an amount of at least 1,000 Rial.',
    'messages.IDPay amount exceeds the 500,000,000 Rial maximum.' => 'IDPay amount exceeds the 500,000,000 Rial maximum.',
    'messages.IDPay verified amount mismatch.' => 'IDPay verified amount mismatch.',
    'messages.IDPay invalid Callback payload.' => 'IDPay invalid Callback payload.',
    'messages.IDPay requires the order id for inquiry.' => 'IDPay requires the order id for inquiry.',
];