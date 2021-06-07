<?php

use App\PayPal\PaypalCredential;
use App\PayPal\PayPalCredentialFactory;
use App\PayPal\PayPalPaymentType;

use function \DI\get;
use function \DI\add;
use function \DI\factory;

return [
    'paypal.id' => env("PAYPAL_ID"),
    'paypal.secret' => env("PAYPAL_SECRET"),
    'paypal.live'   => env("PAYPAL_LIVE"),
    'payments.type' => add([get(PayPalPaymentType::class)]),
    PaypalCredential::class => factory(PayPalCredentialFactory::class),
];
