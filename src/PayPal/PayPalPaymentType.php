<?php
namespace App\PayPal;

use ClientX\Payment\PaymentTypeInterface;

class PayPalPaymentType implements PaymentTypeInterface
{

    public function getName(): string
    {
        return "paypal";
    }
    public function getTitle(): ?string
    {
        return "PayPal";
    }

    public function getManager(): string
    {
        return PayPalPaymentManager::class;
    }

    public function getLogPath(): string
    {
        return "paypal.admin.index";
    }

    public function getIcon():string
    {
        return "fab fa-paypal";
    }

    public function canPayWith(): bool
    {
        return true;
    }
}
