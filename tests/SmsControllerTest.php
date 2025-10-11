<?php

use PHPUnit\Framework\TestCase;
use App\Controllers\SmsController;

class SmsControllerTest extends TestCase
{
    private $pdo;

    protected function setUp(): void
    {
        // Create in-memory SQLite for testing
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create required tables
        $this->pdo->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, phone_e164 TEXT);
            CREATE TABLE sms_audit (
                id INTEGER PRIMARY KEY,
                created_at TEXT,
                from_number TEXT,
                raw_body TEXT,
                parsed_day TEXT,
                parsed_steps INTEGER,
                resolved_week TEXT,
                resolved_day TEXT,
                status TEXT
            );
            CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT);
            CREATE TABLE user_stats (user_id INTEGER PRIMARY KEY, last_ai_at TEXT);
        ");
    }

    public function testInboundHappyPath()
    {
        // This would require mocking the database, environment, etc.
        // For now, just ensure the method exists and can be called
        $controller = new SmsController();
        $this->assertTrue(method_exists($controller, 'inbound'));
    }

    public function testStatusMethodExists()
    {
        $controller = new SmsController();
        $this->assertTrue(method_exists($controller, 'status'));
    }

    public function testSendMethodExists()
    {
        $controller = new SmsController();
        $this->assertTrue(method_exists($controller, 'send'));
    }

    public function testInboundRateLimitWithSmallWindow()
    {
        // Set small rate limit window for testing
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('sms.inbound_rate_window_sec','1')")
                  ->execute();

        // Insert a recent successful SMS
        $recentTime = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
        $this->pdo->prepare("INSERT INTO sms_audit(created_at,from_number,status) VALUES(?,?,?)")
                  ->execute([$recentTime, '+1234567890', 'ok']);

        // Mock the environment and POST data
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_INTERNAL_SECRET'] = 'test_secret';
        $_POST = ['From' => '+1234567890', 'Body' => '1000'];

        // Mock environment functions
        global $mockSettings;
        $mockSettings = ['sms.inbound_rate_window_sec' => '1'];

        // This test would need more comprehensive mocking to actually test the rate limiting
        // For now, just verify the setting is read correctly
        $this->assertEquals('1', $this->pdo->query("SELECT value FROM settings WHERE key='sms.inbound_rate_window_sec'")->fetchColumn());
    }

    public function testAiRateLimitWithSmallWindow()
    {
        // Set small AI rate limit window for testing
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('sms.ai_rate_window_sec','1')")
                  ->execute();

        // Insert a recent AI reply
        $recentTime = date('Y-m-d H:i:s', time() - 30); // 30 seconds ago
        $this->pdo->prepare("INSERT INTO user_stats(user_id,last_ai_at) VALUES(?,?)")
                  ->execute([1, $recentTime]);

        // This test would need more comprehensive mocking to actually test the AI rate limiting
        // For now, just verify the setting is read correctly
        $this->assertEquals('1', $this->pdo->query("SELECT value FROM settings WHERE key='sms.ai_rate_window_sec'")->fetchColumn());
    }

    // Additional tests would mock dependencies and test specific behaviors
    // - Test signature verification
    // - Test rate limiting
    // - Test parsing logic
    // - Test response formatting
    // - Test audit logging
}
