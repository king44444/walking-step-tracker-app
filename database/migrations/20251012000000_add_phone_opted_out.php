<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPhoneOptedOut extends AbstractMigration
{
    public function change(): void
    {
        $t = $this->table('users');
        if (!$t->hasColumn('phone_opted_out')) {
            $t->addColumn('phone_opted_out', 'boolean', ['default' => 0, 'null' => false])
              ->update();
        }
    }
}