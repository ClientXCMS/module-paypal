<?php
namespace App\PayPal;

use PayPalCheckoutSdk\Core\PayPalHttpClient;
use PayPalCheckoutSdk\Core\ProductionEnvironment;
use PayPalCheckoutSdk\Core\SandboxEnvironment;

class PaypalCredential
{

    public string $id;
    public string $secret;
    public bool $live;

    public function __construct($id, $secret, $live)
    {
        $this->check($id);
        $this->check($secret);
        $this->live = $live;
        $this->id = $id;
        $this->secret = $secret;
    }

    private function check($value)
    {
        if ($value === null || empty($value)) {
            throw new \RuntimeException(sprintf("The %s must be defined in .en", $value));
        }
    }

    public function getClient(): PayPalHttpClient
    {
        if ($this->live) {
            return new PayPalHttpClient(new ProductionEnvironment($this->id, $this->secret));
        } else {
            return new PayPalHttpClient(new SandboxEnvironment($this->id, $this->secret));
        }
    }
}
