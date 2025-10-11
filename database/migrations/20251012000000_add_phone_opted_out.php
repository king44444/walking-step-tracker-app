<?php

use Phinx\Migration\AbstractMigration;

class AddPhoneOptedOut extends AbstractMigration
{
    public function change()
    {
        // Add phone_opted_out column to users table for STOP compliance
        $table = $this->table('users');
        $table->addColumn('phone_opted_out', 'boolean', ['default' => 0, 'null' => false])
              ->update();
    }
}
