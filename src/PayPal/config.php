<?php

use App\PayPal\Navigations\Items\PayPalCustomerItem;
use App\PayPal\PayPalContextFactory;
use App\PayPal\PayPalPaymentManager;
use App\PayPal\PayPalPaymentType;
use App\PayPal\Setting\PayPalSetting;

use function ClientX\setting;
use function \DI\get;
use function \DI\add;
use function \DI\factory;
use function \DI\autowire;

return [
    'paypal.id' => setting('paypal_id'),
    'paypal.secret' => setting("paypal_secret"),
    'payments.type' => add([get(PayPalPaymentType::class)]),
    'admin.customer.items' => add([get(PayPalCustomerItem::class)]),
    PayPalPaymentManager::class => autowire()->constructorParameter('context', factory(PayPalContextFactory::class)),
    'admin.settings' => add(get(PayPalSetting::class))
];
