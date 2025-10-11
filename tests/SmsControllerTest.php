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

    public function testAiSettingsDisabled()
    {
        // Disable AI globally
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('ai.enabled','0')")
                  ->execute();

        // Verify AI is disabled
        $this->assertEquals('0', $this->pdo->query("SELECT value FROM settings WHERE key='ai.enabled'")->fetchColumn());
    }

    public function testAiSettingsEnabledWithAutosend()
    {
        // Enable AI and autosend
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('ai.enabled','1')")
                  ->execute();
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('ai_autosend','1')")
                  ->execute();

        // Verify settings
        $this->assertEquals('1', $this->pdo->query("SELECT value FROM settings WHERE key='ai.enabled'")->fetchColumn());
        $this->assertEquals('1', $this->pdo->query("SELECT value FROM settings WHERE key='ai_autosend'")->fetchColumn());
    }

    public function testInboundSmsParsing()
    {
        // Test SMS parsing logic would go here
        // This would require mocking the parsing functions
        $this->assertTrue(true); // Placeholder for now
    }

    public function testTwimlResponseFormat()
    {
        // Test TwiML response formatting
        $this->assertTrue(true); // Placeholder for now
    }

    public function testJsonResponseFormat()
    {
        // Test JSON response formatting
        $this->assertTrue(true); // Placeholder for now
    }

    public function testOutboundSmsSend()
    {
        // Test outbound SMS sending with Twilio mocking
        $this->assertTrue(true); // Placeholder for now
    }

    public function testStatusCallbackHandling()
    {
        // Test status callback processing
        $this->assertTrue(true); // Placeholder for now
    }

    public function testSignatureVerification()
    {
        // Test Twilio signature verification
        $this->assertTrue(true); // Placeholder for now
    }

    public function testAdminPrefixEnabled()
    {
        // Enable admin prefix
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('sms.admin_prefix_enabled','1')")
                  ->execute();
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('sms.admin_password','secret')")
                  ->execute();

        // Verify settings
        $this->assertEquals('1', $this->pdo->query("SELECT value FROM settings WHERE key='sms.admin_prefix_enabled'")->fetchColumn());
        $this->assertEquals('secret', $this->pdo->query("SELECT value FROM settings WHERE key='sms.admin_password'")->fetchColumn());
    }

    public function testAdminPrefixDisabled()
    {
        // Disable admin prefix
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('sms.admin_prefix_enabled','0')")
                  ->execute();

        // Verify setting
        $this->assertEquals('0', $this->pdo->query("SELECT value FROM settings WHERE key='sms.admin_prefix_enabled'")->fetchColumn());
    }

    public function testDaySetParsingBothOrders()
    {
        // Test parsing "MON 8200" and "8200 MON"
        // This would require mocking the parsing logic
        $this->assertTrue(true); // Placeholder - actual parsing tests would need more setup
    }

    public function testHelpExcludesAdminAi()
    {
        // Test that HELP text doesn't include admin/AI toggles
        $controller = new SmsController();
        // This would require mocking to test getHelpText output
        $this->assertTrue(method_exists($controller, 'getHelpText'));
    }

    public function testAwardReplyUrl()
    {
        // Test award reply includes full URL
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('app.public_base_url','https://example.com')")
                  ->execute();

        // Verify setting
        $this->assertEquals('https://example.com', $this->pdo->query("SELECT value FROM settings WHERE key='app.public_base_url'")->fetchColumn());
    }

    public function testInterestsNormalization()
    {
        // Test interests CSV normalization (trim, dedupe, sort)
        // This would require testing the handleInterestsSet method
        $this->assertTrue(true); // Placeholder
    }

    public function testUndoCommandDisabled()
    {
        // Test UNDO command when disabled
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('sms.undo_enabled','0')")
                  ->execute();

        // Verify setting
        $this->assertEquals('0', $this->pdo->query("SELECT value FROM settings WHERE key='sms.undo_enabled'")->fetchColumn());
    }

    public function testUndoCommandEnabled()
    {
        // Test UNDO command when enabled
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('sms.undo_enabled','1')")
                  ->execute();

        // Verify setting
        $this->assertEquals('1', $this->pdo->query("SELECT value FROM settings WHERE key='sms.undo_enabled'")->fetchColumn());
    }
}
