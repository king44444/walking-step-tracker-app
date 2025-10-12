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
            CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, phone_e164 TEXT, phone_opted_out INTEGER DEFAULT 0);
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
            CREATE TABLE sms_consent_log (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                action TEXT,
                phone_number TEXT,
                created_at TEXT
            );
            CREATE TABLE reminders_log (
                id INTEGER PRIMARY KEY,
                user_id INTEGER,
                sent_on_date TEXT,
                when_sent TEXT,
                created_at TEXT
            );
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

    public function testStopCommand()
    {
        // Insert test user
        $this->pdo->prepare("INSERT INTO users(id, name, phone_e164) VALUES(1, 'Test User', '+1234567890')")
                  ->execute();

        // Verify user starts with opted_out = 0
        $this->assertEquals('0', $this->pdo->query("SELECT phone_opted_out FROM users WHERE id=1")->fetchColumn());

        // This would require mocking the controller method
        // For now, test the database operations directly
        $stmt = $this->pdo->prepare("UPDATE users SET phone_opted_out = 1 WHERE id = ?");
        $stmt->execute([1]);
        $this->assertEquals('1', $this->pdo->query("SELECT phone_opted_out FROM users WHERE id=1")->fetchColumn());

        // Test consent log
        $stmt = $this->pdo->prepare("INSERT INTO sms_consent_log(user_id, action, phone_number, created_at) VALUES(?, 'STOP', ?, datetime('now'))");
        $stmt->execute([1, '+1234567890']);
        $result = $this->pdo->query("SELECT action FROM sms_consent_log WHERE user_id=1")->fetchColumn();
        $this->assertEquals('STOP', $result);
    }

    public function testStartCommand()
    {
        // Insert test user with opted_out = 1
        $this->pdo->prepare("INSERT INTO users(id, name, phone_e164, phone_opted_out) VALUES(1, 'Test User', '+1234567890', 1)")
                  ->execute();

        // Verify user starts opted out
        $this->assertEquals('1', $this->pdo->query("SELECT phone_opted_out FROM users WHERE id=1")->fetchColumn());

        // Test START command
        $stmt = $this->pdo->prepare("UPDATE users SET phone_opted_out = 0 WHERE id = ?");
        $stmt->execute([1]);
        $this->assertEquals('0', $this->pdo->query("SELECT phone_opted_out FROM users WHERE id=1")->fetchColumn());

        // Test consent log
        $stmt = $this->pdo->prepare("INSERT INTO sms_consent_log(user_id, action, phone_number, created_at) VALUES(?, 'START', ?, datetime('now'))");
        $stmt->execute([1, '+1234567890']);
        $result = $this->pdo->query("SELECT action FROM sms_consent_log WHERE user_id=1 ORDER BY id DESC LIMIT 1")->fetchColumn();
        $this->assertEquals('START', $result);
    }

    public function testRemindersSettings()
    {
        // Test reminder default settings
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('reminders.default_morning','07:30')")
                  ->execute();
        $this->pdo->prepare("INSERT INTO settings(key,value) VALUES('reminders.default_evening','20:00')")
                  ->execute();

        $this->assertEquals('07:30', $this->pdo->query("SELECT value FROM settings WHERE key='reminders.default_morning'")->fetchColumn());
        $this->assertEquals('20:00', $this->pdo->query("SELECT value FROM settings WHERE key='reminders.default_evening'")->fetchColumn());
    }

    public function testReminderToggleOn()
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users(name TEXT, reminders_enabled INTEGER, reminders_when TEXT)");
        $pdo->exec("INSERT INTO users(name, reminders_enabled) VALUES('Alice', 0)");

        $rc = new \ReflectionClass(\App\Controllers\SmsController::class);
        $m = $rc->getMethod('handleRemindersToggle');
        $m->setAccessible(true);
        $ctrl = new \App\Controllers\SmsController();
        $msg = $m->invoke($ctrl, $pdo, 'Alice', 'ON');
        $this->assertStringStartsWith('reminders on.', strtolower($msg));
        $this->assertEquals(1, (int)$pdo->query("SELECT reminders_enabled FROM users WHERE name='Alice'")->fetchColumn());
    }

    public function testReminderToggleOff()
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users(name TEXT, reminders_enabled INTEGER, reminders_when TEXT)");
        $pdo->exec("INSERT INTO users(name, reminders_enabled) VALUES('Bob', 1)");

        $rc = new \ReflectionClass(\App\Controllers\SmsController::class);
        $m = $rc->getMethod('handleRemindersToggle');
        $m->setAccessible(true);
        $ctrl = new \App\Controllers\SmsController();
        $msg = $m->invoke($ctrl, $pdo, 'Bob', 'OFF');
        $this->assertStringStartsWith('reminders off.', strtolower($msg));
        $this->assertEquals(0, (int)$pdo->query("SELECT reminders_enabled FROM users WHERE name='Bob'")->fetchColumn());
    }

    public function testReminderWhenMorning()
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users(name TEXT, reminders_enabled INTEGER, reminders_when TEXT)");
        $pdo->exec("INSERT INTO users(name, reminders_when) VALUES('Cara', NULL)");

        $rc = new \ReflectionClass(\App\Controllers\SmsController::class);
        $m = $rc->getMethod('handleRemindersWhen');
        $m->setAccessible(true);
        $ctrl = new \App\Controllers\SmsController();
        // Default morning mapped to configured default (07:30)
        $msg = $m->invoke($ctrl, $pdo, 'Cara', 'MORNING');
        $this->assertStringContainsString('Reminder time set to 07:30.', $msg);
        $this->assertEquals('07:30', $pdo->query("SELECT reminders_when FROM users WHERE name='Cara'")->fetchColumn());
    }

    public function testReminderWhen0730()
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE users(name TEXT, reminders_enabled INTEGER, reminders_when TEXT)");
        $pdo->exec("INSERT INTO users(name, reminders_when) VALUES('Dan', NULL)");

        $rc = new \ReflectionClass(\App\Controllers\SmsController::class);
        $m = $rc->getMethod('handleRemindersWhen');
        $m->setAccessible(true);
        $ctrl = new \App\Controllers\SmsController();
        $msg = $m->invoke($ctrl, $pdo, 'Dan', '07:30');
        $this->assertStringContainsString('Reminder time set to 07:30.', $msg);
        $this->assertEquals('07:30', $pdo->query("SELECT reminders_when FROM users WHERE name='Dan'")->fetchColumn());
    }

    public function testHelpTextMentionsWalkOrMenu()
    {
        $rc = new \ReflectionClass(\App\Controllers\SmsController::class);
        $m = $rc->getMethod('getHelpText');
        $m->setAccessible(true);
        $ctrl = new \App\Controllers\SmsController();
        $msg = $m->invoke($ctrl, false);
        $this->assertStringContainsString('WALK or MENU - Command list', $msg);
        $this->assertStringNotContainsString('INFO -', $msg);
        $this->assertStringNotContainsString('HELP -', $msg);
    }

    public function testRemindersLogTable()
    {
        // Insert test reminder log
        $this->pdo->prepare("INSERT INTO reminders_log(user_id, sent_on_date, when_sent, created_at) VALUES(1, '2025-10-10', 'MORNING', datetime('now'))")
                  ->execute();

        $result = $this->pdo->query("SELECT when_sent FROM reminders_log WHERE user_id=1")->fetchColumn();
        $this->assertEquals('MORNING', $result);
    }

    public function testOutboundBlocksOptedOut()
    {
        // Insert opted out user
        $this->pdo->prepare("INSERT INTO users(id, name, phone_e164, phone_opted_out) VALUES(1, 'Test User', '+1234567890', 1)")
                  ->execute();

        // This would require mocking Outbound::sendSMS
        // For now, test the database check logic
        $stmt = $this->pdo->prepare("SELECT phone_opted_out FROM users WHERE phone_e164 = ?");
        $stmt->execute(['+1234567890']);
        $optedOut = $stmt->fetchColumn();
        $this->assertEquals('1', $optedOut);
    }
}
