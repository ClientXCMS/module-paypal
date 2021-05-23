<?php
namespace App\PayPal\Actions;

use ClientX\Actions\Payment\PaymentAdminAction;

class PayPalAdminAction extends PaymentAdminAction
{

    protected $routePrefix = "paypal.admin";
    protected $moduleName = "PayPal";
    protected $paymenttype = "paypal";
}
