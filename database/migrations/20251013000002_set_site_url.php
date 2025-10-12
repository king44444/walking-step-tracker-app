<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SetSiteUrl extends AbstractMigration
{
    public function up(): void
    {
        // Seed or update the canonical site URL used in SMS footers
        $url = 'https://mikebking.com/dev/html/walk/site/';
        $this->execute(
            "INSERT INTO settings(key,value,updated_at) VALUES('site.url', :u, datetime('now'))\n" .
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime('now')",
            [':u' => $url]
        );
    }

    public function down(): void
    {
        // Revert only if the value matches the canonical default set here
        $this->execute(
            "UPDATE settings SET value = NULL, updated_at = datetime('now') WHERE key='site.url' AND value='https://mikebking.com/dev/html/walk/site/'"
        );
    }
}

