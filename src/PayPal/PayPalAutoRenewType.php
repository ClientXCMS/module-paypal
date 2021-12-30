<?php


namespace App\PayPal;

use App\Shop\Entity\Service;
use App\Shop\Entity\Transaction;
use App\Shop\Renew\AutoRenewTypeInterface;
use App\Shop\Renew\RenewResponse;

class PayPalAutoRenewType implements AutoRenewTypeInterface
{

    public function getName(): string
    {
        return "paypal";
    }

    public function getTitle(): string
    {
        return "PayPal";
    }

    public function getSvg(): string
    {
        return "https://clientxcms.com/Themes/CLIENTXCMS/images/modules/PayPal.svg";
    }

    public function fetchToken(string $token, Transaction $transaction): ?RenewResponse
    {
        return null;
    }

    public function subscribe(Service $service): string
    {
        return "XXX";
    }

    public function cancel(Service $service): string
    {
        return "success";
    }
}
