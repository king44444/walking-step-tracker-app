<?php

use Phinx\Migration\AbstractMigration;

class SmsTables extends AbstractMigration
{
    public function change()
    {
        // SMS audit table (for inbound messages)
        $this->table('sms_audit', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('from_number', 'string', ['null' => true])
            ->addColumn('raw_body', 'text', ['null' => true])
            ->addColumn('parsed_day', 'string', ['null' => true])
            ->addColumn('parsed_steps', 'integer', ['null' => true])
            ->addColumn('resolved_week', 'string', ['null' => true])
            ->addColumn('resolved_day', 'string', ['null' => true])
            ->addColumn('status', 'string')
            ->addIndex(['from_number'])
            ->addIndex(['status'])
            ->create();

        // SMS outbound audit table (for sent messages)
        $this->table('sms_outbound_audit', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('to_number', 'string')
            ->addColumn('body', 'text')
            ->addColumn('http_code', 'integer', ['null' => true])
            ->addColumn('sid', 'string', ['null' => true])
            ->addColumn('error', 'text', ['null' => true])
            ->addIndex(['to_number'])
            ->addIndex(['sid'])
            ->create();

        // Message status table (for Twilio status callbacks)
        $this->table('message_status', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true])
            ->addColumn('message_sid', 'string', ['null' => true])
            ->addColumn('message_status', 'string', ['null' => true])
            ->addColumn('to_number', 'string', ['null' => true])
            ->addColumn('from_number', 'string', ['null' => true])
            ->addColumn('error_code', 'string', ['null' => true])
            ->addColumn('error_message', 'text', ['null' => true])
            ->addColumn('messaging_service_sid', 'string', ['null' => true])
            ->addColumn('account_sid', 'string', ['null' => true])
            ->addColumn('api_version', 'string', ['null' => true])
            ->addColumn('raw_payload', 'text', ['null' => true])
            ->addColumn('received_at_utc', 'datetime')
            ->addIndex(['message_sid'], ['unique' => true])
            ->addIndex(['message_status'])
            ->create();

        // Settings table
        $this->table('settings', ['id' => false, 'primary_key' => ['key']])
            ->addColumn('key', 'string')
            ->addColumn('value', 'text', ['null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->create();

        // User stats table (for AI rate limiting)
        $this->table('user_stats', ['id' => false, 'primary_key' => 'user_id'])
            ->addColumn('user_id', 'integer')
            ->addColumn('last_ai_at', 'datetime', ['null' => true])
            ->create();

        // SMS consent log table (for STOP/START tracking)
        $this->table('sms_consent_log', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('action', 'string', ['null' => false]) // 'STOP' or 'START'
            ->addColumn('phone_number', 'string', ['null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'])
            ->addIndex(['action'])
            ->create();

        // Reminders log table (prevent duplicate daily sends)
        $this->table('reminders_log', ['id' => false, 'primary_key' => 'id'])
            ->addColumn('id', 'integer', ['identity' => true])
            ->addColumn('user_id', 'integer', ['null' => false])
            ->addColumn('sent_on_date', 'string', ['null' => false]) // YYYY-MM-DD
            ->addColumn('when_sent', 'string', ['null' => false]) // 'MORNING', 'EVENING', 'HH:MM'
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['user_id'])
            ->addIndex(['sent_on_date'])
            ->create();
    }
}
