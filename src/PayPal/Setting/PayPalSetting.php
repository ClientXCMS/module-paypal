<?php
namespace App\PayPal\Setting;

use App\Admin\Settings\SettingsInterface;
use ClientX\Renderer\RendererInterface;
use ClientX\Validator;

class PayPalSetting implements SettingsInterface
{

    public function name(): string
    {
        return "paypal";
    }
    public function title(): string
    {
        return "PayPal";
    }

    public function validate(array $params): Validator
    {
        return (new Validator($params))
            ->notEmpty("paypal_id", "paypal_secret")
            ->required("paypal_id", "paypal_secret");
    }
    public function render(RendererInterface $renderer)
    {
        return $renderer->render("@paypal_admin/setting");
    }

    public function icon(): string
    {
        return "fab fa-paypal";
    }
}
