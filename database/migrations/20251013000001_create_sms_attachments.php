<?php

use Phinx\Migration\AbstractMigration;

class CreateSmsAttachments extends AbstractMigration
{
    public function change()
    {
        // SMS attachments table for file uploads
        $this->table('sms_attachments', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('filename', 'string', ['null' => false])
            ->addColumn('url', 'string', ['null' => false])
            ->addColumn('mime_type', 'string', ['null' => false])
            ->addColumn('size', 'integer', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'])
            ->create();
    }
}
