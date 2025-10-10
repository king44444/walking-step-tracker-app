<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class UpdateAwardImagePaths extends AbstractMigration
{
    public function up(): void
    {
        // Update existing rows to point to the deployed webp files for user_id = 8
        $this->execute("UPDATE ai_awards SET image_path='awards/8/lifetime-steps-5000-20251010.webp' WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=5000;");
        $this->execute("UPDATE ai_awards SET image_path='awards/8/lifetime-steps-10000-20251010.webp' WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=10000;");
        $this->execute("UPDATE ai_awards SET image_path='awards/8/lifetime-steps-100000-20251010.webp' WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=100000;");
        $this->execute("UPDATE ai_awards SET image_path='awards/8/lifetime-steps-250000-20251010.webp' WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=250000;");
        $this->execute("UPDATE ai_awards SET image_path='awards/8/lifetime-steps-500000-20251010.webp' WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=500000;");

        // Insert missing rows if they don't exist (keep created_at as now)
        $this->execute("INSERT INTO ai_awards(user_id, kind, milestone_value, image_path, created_at) SELECT 8,'lifetime_steps',5000,'awards/8/lifetime-steps-5000-20251010.webp', datetime('now') WHERE NOT EXISTS(SELECT 1 FROM ai_awards WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=5000);");
        $this->execute("INSERT INTO ai_awards(user_id, kind, milestone_value, image_path, created_at) SELECT 8,'lifetime_steps',10000,'awards/8/lifetime-steps-10000-20251010.webp', datetime('now') WHERE NOT EXISTS(SELECT 1 FROM ai_awards WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=10000);");
        $this->execute("INSERT INTO ai_awards(user_id, kind, milestone_value, image_path, created_at) SELECT 8,'lifetime_steps',100000,'awards/8/lifetime-steps-100000-20251010.webp', datetime('now') WHERE NOT EXISTS(SELECT 1 FROM ai_awards WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=100000);");
        $this->execute("INSERT INTO ai_awards(user_id, kind, milestone_value, image_path, created_at) SELECT 8,'lifetime_steps',250000,'awards/8/lifetime-steps-250000-20251010.webp', datetime('now') WHERE NOT EXISTS(SELECT 1 FROM ai_awards WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=250000);");
        $this->execute("INSERT INTO ai_awards(user_id, kind, milestone_value, image_path, created_at) SELECT 8,'lifetime_steps',500000,'awards/8/lifetime-steps-500000-20251010.webp', datetime('now') WHERE NOT EXISTS(SELECT 1 FROM ai_awards WHERE user_id=8 AND kind='lifetime_steps' AND milestone_value=500000);");
    }

    public function down(): void
    {
        // Revert the image_path changes for the specific deployed filenames created by this migration
        $this->execute("UPDATE ai_awards SET image_path = NULL WHERE user_id=8 AND kind='lifetime_steps' AND image_path LIKE 'awards/8/lifetime-steps-%-20251010.webp';");
    }
}
