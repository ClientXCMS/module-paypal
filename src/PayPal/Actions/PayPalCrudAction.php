<?php
namespace App\PayPal\Actions;

use App\PayPal\Database\PayPalTable;
use ClientX\Actions\CrudAction;
use ClientX\Renderer\RendererInterface;
use ClientX\Router;
use ClientX\Session\FlashService;

class PayPalCrudAction extends CrudAction
{

    protected $viewPath = "@paypal_admin";
    protected $routePrefix = "paypal.admin";
    protected $moduleName = "PayPal";

    public function __construct(RendererInterface $renderer, PayPalTable $table, FlashService $flash, Router $router)
    {
        parent::__construct($renderer, $table, $router, $flash);
    }

    protected function create(\Psr\Http\Message\ServerRequestInterface $request)
    {
        return $this->redirect($this->routePrefix . '.index');
    }
}
