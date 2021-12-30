<?php

namespace App\PayPal;

class PayPalPaymentBoard extends \App\Shop\Payment\AbstractPaymentBoard
{
    protected string $entity = PayPalPaymentType::class;
    protected string $type = "paypal";
}