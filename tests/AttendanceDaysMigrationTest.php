<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Phinx\Db\Adapter\SQLiteAdapter;

require_once __DIR__ . '/../database/migrations/20251016000100_attendance_days.php';

final class AttendanceDaysMigrationTest extends TestCase
{
    private PDO $pdo;
    private SQLiteAdapter $adapter;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->createSchema();

        $this->adapter = new SQLiteAdapter([
            'name' => 'memory',
            'connection' => $this->pdo,
            'migration_table' => 'phinxlog',
        ]);
    }

    public function testMigrationConversion(): void
    {
        $this->seedLegacyData();

        $migration = new AttendanceDays('testing', 20251016000100);
        $migration->setAdapter($this->adapter);
        $migration->up();

        $value = $this->pdo->query("SELECT value FROM settings WHERE key = 'milestones.attendance_days'")->fetchColumn();
        $this->assertEquals('175,350,700', $value);

        $legacyValue = $this->pdo->query("SELECT value FROM settings WHERE key = 'milestones.attendance_weeks'")->fetchColumn();
        $this->assertFalse($legacyValue);

        $award = $this->pdo->query("SELECT kind, milestone_value FROM ai_awards WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('attendance_days', $award['kind']);
        $this->assertEquals(175, (int)$award['milestone_value']);

        $cache = $this->pdo->query("SELECT award_key, threshold FROM user_awards_cache WHERE user_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('attendance_days_175', $cache['award_key']);
        $this->assertEquals(175, (int)$cache['threshold']);

        $migration->down();

        $reverted = $this->pdo->query("SELECT value FROM settings WHERE key = 'milestones.attendance_weeks'")->fetchColumn();
        $this->assertEquals('25,50,100', $reverted);

        $award = $this->pdo->query("SELECT kind, milestone_value FROM ai_awards WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('attendance_weeks', $award['kind']);
        $this->assertEquals(25, (int)$award['milestone_value']);

        $cache = $this->pdo->query("SELECT award_key, threshold FROM user_awards_cache WHERE user_id = 1")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('attendance_weeks_25', $cache['award_key']);
        $this->assertEquals(25, (int)$cache['threshold']);
    }

    private function createSchema(): void
    {
        $this->pdo->exec("
            CREATE TABLE settings (
                key TEXT PRIMARY KEY,
                value TEXT,
                updated_at TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE ai_awards (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                kind TEXT,
                milestone_value INTEGER,
                week TEXT,
                image_path TEXT,
                meta TEXT
            )
        ");

        $this->pdo->exec("
            CREATE TABLE user_awards_cache (
                user_id INTEGER NOT NULL,
                award_key TEXT NOT NULL,
                threshold INTEGER NOT NULL,
                awarded_at TEXT NOT NULL,
                PRIMARY KEY (user_id, award_key)
            )
        ");
    }

    private function seedLegacyData(): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO settings(key, value, updated_at) VALUES(:k, :v, :u)");
        $stmt->execute([
            ':k' => 'milestones.attendance_weeks',
            ':v' => '25,50,100',
            ':u' => '2025-10-10T00:00:00Z',
        ]);

        $this->pdo->exec("
            INSERT INTO ai_awards (id, user_id, kind, milestone_value, week, image_path, meta)
            VALUES (1, 1, 'attendance_weeks', 25, NULL, NULL, NULL)
        ");

        $this->pdo->exec("
            INSERT INTO user_awards_cache (user_id, award_key, threshold, awarded_at)
            VALUES (1, 'attendance_weeks_25', 25, '2025-10-01')
        ");
    }
}
