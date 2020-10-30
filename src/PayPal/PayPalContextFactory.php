<?php
namespace App\PayPal;

use PayPal\Auth\OAuthTokenCredential;
use PayPal\Rest\ApiContext;
use Psr\Container\ContainerInterface;

class PayPalContextFactory
{

    public function __invoke(ContainerInterface $container)
    {
         return new ApiContext(new OAuthTokenCredential(
             $container->get('paypal.id'),
             $container->get('paypal.secret')
         ));
    }
}
