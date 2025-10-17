<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SetSiteUrl extends AbstractMigration
{
    public function up(): void
    {
        // Seed or update the canonical site URL used in SMS footers.
        // Pull from environment so deployments can provide their own value.
        $url = getenv('SITE_URL') ?: getenv('APP_SITE_URL') ?: null;
        if (!$url) {
            // Nothing to seed; leave existing settings untouched.
            return;
        }

        $this->execute(
            "INSERT INTO settings(key,value,updated_at) VALUES('site.url', :u, datetime('now'))\n" .
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime('now')",
            [':u' => $url]
        );
    }

    public function down(): void
    {
        // Revert only if the value matches the configured default.
        $url = getenv('SITE_URL') ?: getenv('APP_SITE_URL') ?: null;
        if (!$url) {
            return;
        }

        $this->execute(
            "UPDATE settings SET value = NULL, updated_at = datetime('now') WHERE key='site.url' AND value=:url",
            [':url' => $url]
        );
    }
}
