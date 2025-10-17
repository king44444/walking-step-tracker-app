<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../api/lib/awards.php';
require_once __DIR__ . '/../api/lib/dates.php';

/**
 * Tests for the awards system
 */
class AwardsTest extends TestCase
{
    private PDO $pdo;
    
    protected function setUp(): void
    {
        // Use in-memory SQLite for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create test schema
        $this->createTestSchema();
    }
    
    private function createTestSchema(): void
    {
        // Users table
        $this->pdo->exec("
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL
            )
        ");
        
        // Weeks table
        $this->pdo->exec("
            CREATE TABLE weeks (
                id INTEGER PRIMARY KEY,
                starts_on TEXT NOT NULL
            )
        ");
        
        // Entries table
        $this->pdo->exec("
            CREATE TABLE entries (
                id INTEGER PRIMARY KEY,
                user_id INTEGER NOT NULL,
                week_id INTEGER NOT NULL,
                mon INTEGER DEFAULT 0,
                tue INTEGER DEFAULT 0,
                wed INTEGER DEFAULT 0,
                thu INTEGER DEFAULT 0,
                fri INTEGER DEFAULT 0,
                sat INTEGER DEFAULT 0
            )
        ");
        
        // Cache table
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
    
    public function testFormatThreshold(): void
    {
        $this->assertEquals('100,000 Steps', format_threshold(100000));
        $this->assertEquals('250,000 Steps', format_threshold(250000));
        $this->assertEquals('1,000,000 Steps', format_threshold(1000000));
    }
    
    public function testExpandWeekToDailyDates(): void
    {
        $weekStart = '2025-08-11'; // Monday
        $daySteps = [
            'monday' => 5000,
            'tuesday' => 6000,
            'wednesday' => 7000,
            'thursday' => 8000,
            'friday' => 9000,
            'saturday' => 10000
        ];
        
        $result = expand_week_to_daily_dates($weekStart, $daySteps);
        
        $this->assertCount(6, $result);
        $this->assertEquals(5000, $result['2025-08-11']);
        $this->assertEquals(6000, $result['2025-08-12']);
        $this->assertEquals(10000, $result['2025-08-16']);
    }
    
    public function testComputeAwardedDateNoData(): void
    {
        // Insert test user with no entries
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Test User')");
        
        $result = compute_awarded_date($this->pdo, 1, 100000);
        
        $this->assertNull($result);
    }
    
    public function testComputeAwardedDateWithData(): void
    {
        // Insert test user
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Test User')");
        
        // Insert test weeks
        $this->pdo->exec("INSERT INTO weeks (id, starts_on) VALUES (1, '2025-08-04')");
        $this->pdo->exec("INSERT INTO weeks (id, starts_on) VALUES (2, '2025-08-11')");
        
        // Insert entries that will reach 100,000 threshold on second week Wednesday
        $this->pdo->exec("
            INSERT INTO entries (user_id, week_id, mon, tue, wed, thu, fri, sat)
            VALUES (1, 1, 10000, 10000, 10000, 10000, 10000, 10000)
        ");
        $this->pdo->exec("
            INSERT INTO entries (user_id, week_id, mon, tue, wed, thu, fri, sat)
            VALUES (1, 2, 10000, 10000, 10000, 0, 0, 0)
        ");
        
        // Total: 60000 + 30000 = 90000, should reach 100k on Wed of week 2
        // Actually need more...
        $result = compute_awarded_date($this->pdo, 1, 90000);
        
        $this->assertNotNull($result);
        // Should be 2025-08-13 (Wednesday of second week when cumulative reaches 90k)
        $this->assertEquals('2025-08-13', $result);
    }
    
    public function testGetLifetimeAwardsNoSteps(): void
    {
        // Insert test user with no entries
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Test User')");
        
        $awards = get_lifetime_awards($this->pdo, 1);
        
        $this->assertIsArray($awards);
        $this->assertCount(5, $awards); // 5 thresholds
        
        // All should be locked
        foreach ($awards as $award) {
            $this->assertFalse($award['earned']);
            $this->assertNull($award['awarded_at']);
        }
    }
    
    public function testGetLifetimeAwardsWithSteps(): void
    {
        // Insert test user
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Test User')");
        
        // Insert test week
        $this->pdo->exec("INSERT INTO weeks (id, starts_on) VALUES (1, '2025-08-04')");
        
        // Insert entry with 150,000 steps (should earn 100k threshold)
        $this->pdo->exec("
            INSERT INTO entries (user_id, week_id, mon, tue, wed, thu, fri, sat)
            VALUES (1, 1, 25000, 25000, 25000, 25000, 25000, 25000)
        ");
        
        $awards = get_lifetime_awards($this->pdo, 1);
        
        $this->assertIsArray($awards);
        $this->assertCount(5, $awards);
        
        // First award (100k) should be earned
        $firstAward = $awards[0];
        $this->assertTrue($firstAward['earned']);
        $this->assertEquals(100000, $firstAward['threshold']);
        $this->assertNotNull($firstAward['awarded_at']);
        // With 25k per day, threshold 100k is reached on Thursday (08-07)
        $this->assertEquals('2025-08-07', $firstAward['awarded_at']);
        
        // Second award (250k) should be locked
        $secondAward = $awards[1];
        $this->assertFalse($secondAward['earned']);
    }
    
    public function testAwardsCaching(): void
    {
        // Insert test user
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Test User')");
        
        // Insert test week
        $this->pdo->exec("INSERT INTO weeks (id, starts_on) VALUES (1, '2025-08-04')");
        
        // Insert entry with 150,000 steps
        $this->pdo->exec("
            INSERT INTO entries (user_id, week_id, mon, tue, wed, thu, fri, sat)
            VALUES (1, 1, 25000, 25000, 25000, 25000, 25000, 25000)
        ");
        
        // First call - should compute and cache
        $awards1 = get_lifetime_awards($this->pdo, 1);
        
        // Check cache was written
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM user_awards_cache WHERE user_id = 1");
        $stmt->execute();
        $cacheCount = $stmt->fetchColumn();
        $this->assertEquals(1, $cacheCount); // Only 100k award earned
        
        // Second call - should use cache
        $awards2 = get_lifetime_awards($this->pdo, 1);
        
        // Results should be identical
        $this->assertEquals($awards1[0]['awarded_at'], $awards2[0]['awarded_at']);
    }

    public function testGetAttendanceDaysNoData(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Test User')");

        $awards = get_attendance_days_awards($this->pdo, 1, [2, 4]);

        $this->assertCount(2, $awards);
        foreach ($awards as $award) {
            $this->assertFalse($award['earned']);
            $this->assertNull($award['awarded_at']);
            $this->assertEquals('assets/admin/no-photo.svg', $award['image_url']);
        }
    }

    public function testGetAttendanceDaysWithSparseData(): void
    {
        $this->pdo->exec("INSERT INTO users (id, name) VALUES (1, 'Test User')");
        $this->pdo->exec("INSERT INTO weeks (id, starts_on) VALUES (1, '2025-08-11')");
        $this->pdo->exec("
            INSERT INTO entries (user_id, week_id, mon, tue, wed, thu, fri, sat)
            VALUES (1, 1, 1000, 0, 1500, 0, 800, 0)
        ");

        $awards = get_attendance_days_awards($this->pdo, 1, [1, 3]);

        $this->assertCount(2, $awards);

        $first = $awards[0];
        $this->assertTrue($first['earned']);
        $this->assertEquals(1, $first['threshold']);
        $this->assertEquals('2025-08-11', $first['awarded_at']);

        $second = $awards[1];
        $this->assertTrue($second['earned']);
        $this->assertEquals(3, $second['threshold']);
        $this->assertEquals('2025-08-15', $second['awarded_at']);

        $stmt = $this->pdo->query("SELECT award_key, threshold, awarded_at FROM user_awards_cache ORDER BY threshold");
        $cacheRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $cacheRows);
        $this->assertEquals('attendance_days_1', $cacheRows[0]['award_key']);
        $this->assertEquals('attendance_days_3', $cacheRows[1]['award_key']);
    }
}
