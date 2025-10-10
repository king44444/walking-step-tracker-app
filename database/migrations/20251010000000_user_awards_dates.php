<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UserAwardsDates extends AbstractMigration
{
    public function up(): void
    {
        // Create user_awards_cache table for caching computed award dates
        $this->execute(<<<SQL
CREATE TABLE IF NOT EXISTS user_awards_cache (
  user_id INTEGER NOT NULL,
  award_key TEXT NOT NULL,
  threshold INTEGER NOT NULL,
  awarded_at TEXT NOT NULL,
  PRIMARY KEY (user_id, award_key),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
SQL);

        // Add indexes for performance
        $this->execute("CREATE INDEX IF NOT EXISTS idx_awardscache_user ON user_awards_cache(user_id);");
        
        // Add index on entries table for efficient queries
        $this->execute("CREATE INDEX IF NOT EXISTS idx_entries_user_week ON entries(user_id, week_id);");
    }

    public function down(): void
    {
        $this->execute("DROP INDEX IF EXISTS idx_awardscache_user;");
        $this->execute("DROP INDEX IF EXISTS idx_entries_user_week;");
        $this->execute("DROP TABLE IF EXISTS user_awards_cache;");
    }
}
