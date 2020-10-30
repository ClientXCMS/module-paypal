<?php
namespace App\PayPal;

use App\PayPal\Actions\PayPalCrudAction;
use ClientX\Module;
use ClientX\Renderer\RendererInterface;
use ClientX\Router;
use ClientX\Theme\ThemeInterface;
use Psr\Container\ContainerInterface;

class PayPalModule extends Module
{

    const DEFINITIONS = __DIR__ . '/config.php';

    const MIGRATIONS = __DIR__ . '/db/migrations';

    public function __construct(ContainerInterface $container, ThemeInterface $theme)
    {
        $container->get(RendererInterface::class)->addPath('paypal_admin', __DIR__ . '/Views');
        if ($container->has('admin.prefix')) {
            $prefix = $container->get('admin.prefix');
            $container->get(Router::class)->crud($prefix . '/paypal', PayPalCrudAction::class, 'paypal.admin');
        }
    }
}
