<?php
declare(strict_types=1);
use Phinx\Migration\AbstractMigration;

final class Init extends AbstractMigration {
  public function change(): void {
    if (!$this->hasTable('users')) {
      $this->table('users', ['id' => true, 'primary_key' => ['id']])
        ->addColumn('name', 'string')
        ->addColumn('phone', 'string', ['null' => true])
        ->addColumn('sex', 'string', ['null' => true])
        ->addColumn('age', 'integer', ['null' => true])
        ->addColumn('tag', 'string', ['null' => true])
        ->addColumn('photo_path', 'string', ['null' => true])
        ->addColumn('photo_consent', 'boolean', ['default' => 0])
        ->addTimestamps()
        ->create();
    }

    if (!$this->hasTable('weeks')) {
      $this->table('weeks', ['id' => true, 'primary_key' => ['id']])
        ->addColumn('label', 'string')
        ->addColumn('starts_on', 'date')
        ->addColumn('finalized_at', 'datetime', ['null' => true])
        ->addTimestamps()
        ->create();
    }

    if (!$this->hasTable('entries')) {
      $this->table('entries', ['id' => true, 'primary_key' => ['id']])
        ->addColumn('user_id', 'integer')
        ->addColumn('week_id', 'integer')
        ->addColumn('mon', 'integer', ['default' => 0])
        ->addColumn('tue', 'integer', ['default' => 0])
        ->addColumn('wed', 'integer', ['default' => 0])
        ->addColumn('thu', 'integer', ['default' => 0])
        ->addColumn('fri', 'integer', ['default' => 0])
        ->addColumn('sat', 'integer', ['default' => 0])
        ->addColumn('sun', 'integer', ['default' => 0])
        ->addColumn('locked', 'boolean', ['default' => 0])
        ->addColumn('human_reviewed', 'boolean', ['default' => 0])
        ->addTimestamps()
        ->addIndex(['user_id','week_id'], ['unique' => true])
        ->create();
    }

    if (!$this->hasTable('ai_messages')) {
      $this->table('ai_messages', ['id' => true, 'primary_key' => ['id']])
        ->addColumn('week_id', 'integer', ['null' => true])
        ->addColumn('user_id', 'integer', ['null' => true])
        ->addColumn('body', 'text')
        ->addColumn('approved', 'boolean', ['default' => 0])
        ->addColumn('sent_at', 'datetime', ['null' => true])
        ->addTimestamps()
        ->create();
    }

    if (!$this->hasTable('outbound_audit')) {
      $this->table('outbound_audit', ['id' => true, 'primary_key' => ['id']])
        ->addColumn('to_phone', 'string')
        ->addColumn('body', 'text')
        ->addColumn('provider', 'string', ['default' => 'twilio'])
        ->addColumn('status', 'string', ['default' => 'queued'])
        ->addColumn('provider_id', 'string', ['null' => true])
        ->addColumn('error', 'text', ['null' => true])
        ->addTimestamps()
        ->create();
    }
  }
}
