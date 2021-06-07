<?php
namespace App\PayPal;

use Psr\Container\ContainerInterface;

class PayPalCredentialFactory
{

    public function __invoke(ContainerInterface $container)
    {
            $id = $container->get('paypal.id');
            $secret = $container->get('paypal.secret');
            $live = $container->get('paypal.live');
         return new PaypalCredential($id, $secret, $live === "true");
    }
}
