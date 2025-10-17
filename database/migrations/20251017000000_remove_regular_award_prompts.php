<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class RemoveRegularAwardPrompts extends AbstractMigration
{
    public function up(): void
    {
        $this->execute("DELETE FROM settings WHERE key = 'ai.image.prompts.regular';");
    }

    public function down(): void
    {
        $defaults = json_encode([
            [
                'name' => 'Classic Badge',
                'text' => 'Create a flat, minimalist badge icon for {userName} achieving {awardLabel} ({milestone}). Use a dark blue background, crisp edges, and readable text. No faces. Square 512x512.',
                'enabled' => true,
            ],
            [
                'name' => 'Modern Medal',
                'text' => 'Design a contemporary medal-style award for {userName} reaching {awardLabel} ({milestone}). Clean geometric design with metallic accents. Square 512x512.',
                'enabled' => true,
            ],
            [
                'name' => 'Achievement Ribbon',
                'text' => 'Create an elegant ribbon award design celebrating {userName}\'s {awardLabel} milestone ({milestone}). Flowing ribbon style with achievement symbolism. Square 512x512.',
                'enabled' => true,
            ],
        ], JSON_UNESCAPED_SLASHES);

        $escaped = str_replace("'", "''", (string)$defaults);
        $this->execute("INSERT INTO settings(key,value,updated_at) VALUES('ai.image.prompts.regular','{$escaped}',datetime('now'))
            ON CONFLICT(key) DO UPDATE SET value='{$escaped}', updated_at=datetime('now');");
    }
}
