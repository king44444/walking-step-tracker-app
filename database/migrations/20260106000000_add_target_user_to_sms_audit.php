<?php

use Phinx\Migration\AbstractMigration;

class AddTargetUserToSmsAudit extends AbstractMigration
{
    public function change()
    {
        $this->table('sms_audit')
            ->addColumn('target_user_name', 'string', ['null' => true, 'after' => 'from_number'])
            ->update();
    }
}
