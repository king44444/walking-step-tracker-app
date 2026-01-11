<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpdateSmsRateLimit extends AbstractMigration
{
    public function up(): void
    {
        // Update SMS inbound rate limit from 60 seconds to 4 seconds
        $this->execute(
            "INSERT INTO settings(key,value,updated_at) VALUES('sms.inbound_rate_window_sec', '4', datetime('now'))\n" .
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime('now')"
        );
    }

    public function down(): void
    {
        // Revert back to 60 seconds
        $this->execute(
            "UPDATE settings SET value = '60', updated_at = datetime('now') WHERE key='sms.inbound_rate_window_sec'"
        );
    }
}
