<?php

use Phinx\Migration\AbstractMigration;

class CreatePayPalTransactionsTable extends AbstractMigration
{
    const TABLE_NAME = "paypal_transactions";
    public function change()
    {
        $table = $this->table('paypal_transactions');
        if ($table->exist()){
            return;
        }
        $table
            ->addColumn('payment_id', 'string')
            ->addColumn('user_id', 'integer')
            ->addColumn('state', 'string')
            ->addColumn('payer_id', 'string')
            ->addColumn('payer_email', 'string')
            ->addColumn('payer_country', 'string')
            ->addColumn('total', 'float', ['precision' => 6, 'scale' => 2])
            ->addColumn('subtotal', 'float', ['precision' => 6, 'scale' => 2])
            ->addColumn('tax', 'float', ['precision' => 6, 'scale' => 2])
            ->addForeignKey('user_id', 'users', 'id')
            ->addTimestamps()
            ->create();
    }
}
