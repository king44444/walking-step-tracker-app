<?php
use Phinx\Migration\AbstractMigration;

class Baseline extends AbstractMigration
{
    public function up(): void
    {
        // baseline migration — intentionally empty to anchor schema version
    }

    public function down(): void
    {
        // no-op
    }
}
