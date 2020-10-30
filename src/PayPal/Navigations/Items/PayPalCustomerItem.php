<?php
namespace App\PayPal\Navigations\Items;

use App\PayPal\Database\PayPalTable;
use ClientX\Auth\User;
use ClientX\Navigation\NavigationItemInterface;
use ClientX\Renderer\RendererInterface;

class PayPalCustomerItem implements NavigationItemInterface
{


    /**
     * @var PayPalTable
     */
    private $table;

    /**
     * @var RendererInterface
     */
    private $renderer;

    /**
     * @var User
     */
    private $user;

    public function __construct(RendererInterface $renderer, PayPalTable $table)
    {
        $this->renderer = $renderer;
        $this->table    = $table;
    }

    public function render(): string
    {
        $transactions = $this->table->findForUser($this->user->getId());
        return $this->renderer->render('@paypal_admin/customer', compact('transactions'));
    }

    public function getPosition(): int
    {
        return 100;
    }

    public function setUser(User $user)
    {
        $this->user = $user;
    }
}
