<?php

use Phinx\Migration\AbstractMigration;

class AddSmsMetaColumns extends AbstractMigration
{
    public function change()
    {
        // Add meta columns for attachment storage
        $this->table('sms_audit')
            ->addColumn('meta', 'text', ['null' => true])
            ->update();

        $this->table('sms_outbound_audit')
            ->addColumn('meta', 'text', ['null' => true])
            ->update();
    }
}
