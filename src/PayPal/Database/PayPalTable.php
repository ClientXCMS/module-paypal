<?php
namespace App\PayPal\Database;

use ClientX\Database\Table;

class PayPalTable extends Table
{

    protected $table = "paypal_transactions";
    protected $element = "payment_id";

    public function findForUser(int $id)
    {
        return $this->makeQuery()->where('user_id = :id')->params(compact('id'));
    }
}
