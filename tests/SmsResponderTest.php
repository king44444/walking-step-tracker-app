<?php

use PHPUnit\Framework\TestCase;
use App\Http\Responders\SmsResponder;

class SmsResponderTest extends TestCase
{
    private int $baseObLevel;

    protected function setUp(): void
    {
        // Record base buffer level and start one buffer this test will own
        $this->baseObLevel = ob_get_level();
        ob_start();
        // Ensure SITE_URL is always available for footer in full-suite runs
        if (!isset($_ENV['SITE_URL'])) {
            $_ENV['SITE_URL'] = 'https://example.com/walk/site/';
        }
    }

    protected function tearDown(): void
    {
        // Restore buffer level to what it was before this test
        while (ob_get_level() > $this->baseObLevel) {
            ob_end_clean();
        }
        // Clear request context between tests
        unset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);
        unset($_GET['format']);
        // Do not unset SITE_URL here; some suites rely on it globally
    }

    public function testOkResponseWithTwilioHeaderReturnsXml()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        SmsResponder::ok('Test message');

        $output = ob_get_clean();
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContainsString('<Response><Message>Test message', $output);
    }

    public function testOkResponseWithoutTwilioHeaderReturnsJson()
    {
        unset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);

        SmsResponder::ok('Test message');

        $output = ob_get_clean();
        $json = json_decode($output, true);
        $this->assertTrue($json['ok']);
        $this->assertStringStartsWith('Test message', $json['message']);
    }

    public function testErrorResponseWithTwilioHeaderReturnsXml()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        SmsResponder::error('Error message', 'test_error', 400);

        $output = ob_get_clean();
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContainsString('<Response><Message>Error message', $output);
    }

    public function testErrorResponseWithoutTwilioHeaderReturnsJson()
    {
        unset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);

        SmsResponder::error('Error message', 'test_error', 400);

        $output = ob_get_clean();
        $json = json_decode($output, true);
        $this->assertEquals('test_error', $json['error']);
        $this->assertStringStartsWith('Error message', $json['message']);
    }

    public function testExplicitFormatQueryParamOverridesHeader()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_GET['format'] = 'json';

        SmsResponder::ok('Test message');

        $output = ob_get_clean();
        $json = json_decode($output, true);
        $this->assertTrue($json['ok']);
        $this->assertStringStartsWith('Test message', $json['message']);
    }

    public function testInvalidFormatQueryParamDefaultsToHeader()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_GET['format'] = 'invalid';

        SmsResponder::ok('Test message');

        $output = ob_get_clean();
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $output);
    }

    public function testXmlEscaping()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        SmsResponder::ok('Message with <tags> & "quotes"');

        $output = ob_get_clean();
        // Expect properly escaped XML content
        $this->assertStringContainsString('Message with &lt;tags&gt; &amp; &quot;quotes&quot;', $output);
    }

    public function testFooterAppendedForPlainNumber()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        // Use canonical base; normalization should ensure single trailing slash
        $_ENV['SITE_URL'] = 'https://example.com/walk/site';

        SmsResponder::ok('Recorded 1,240 for Mike on today.');
        $output = ob_get_clean();
        $this->assertStringContainsString('Recorded 1,240 for Mike on today.', $output);
        $this->assertStringContainsString("\nVisit https://example.com/walk/site/", $output);
        $this->assertStringContainsString('text &quot;walk&quot; or &quot;menu&quot; for menu', $output);
    }

    public function testFooterAppendedForDayNumber()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_ENV['SITE_URL'] = 'https://example.com/walk/site/';

        SmsResponder::ok('Recorded 1,240 for Mike on tuesday.');
        $output = ob_get_clean();
        $this->assertStringContainsString('Recorded 1,240 for Mike on tuesday.', $output);
        $this->assertStringContainsString("\nVisit https://example.com/walk/site/", $output);
    }

    public function testNoDuplicateWhenUrlAlreadyPresent()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_ENV['SITE_URL'] = 'https://example.com/walk/site';

        $msg = 'Recorded 1,240 for Mike on today. Visit https://example.com/walk/site/ — text "walk" or "menu" for menu.';
        SmsResponder::ok($msg);
        $output = ob_get_clean();
        // The message should not contain the URL twice
        $this->assertEquals(1, substr_count($output, 'https://example.com/walk/site/'));
    }

    public function testMenuBlockHasNoUrlAndFooterOnNewLine()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_ENV['SITE_URL'] = 'https://example.com/walk/site/';

        // Build the menu text from controller
        $rc = new \ReflectionClass(\App\Controllers\SmsController::class);
        $m = $rc->getMethod('getHelpText');
        $m->setAccessible(true);
        $ctrl = new \App\Controllers\SmsController();
        $menu = $m->invoke($ctrl, false);

        // Sanity check: menu ends with the command list line and contains no URL
        $this->assertStringEndsWith('WALK or MENU - Command list', $menu);
        $this->assertStringNotContainsString('http', $menu);

        SmsResponder::ok($menu);
        $output = ob_get_clean();

        // Footer appended once, on a new line, with canonical URL
        $this->assertStringContainsString("\nVisit https://example.com/walk/site/", $output);
        // Ensure the URL is not inside the command block
        $this->assertStringNotContainsString('WALK or MENU - Command list Visit', $output);
    }

    public function testFooterSingleTrailingSlashNormalization()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_ENV['SITE_URL'] = 'https://example.com/walk/site////';
        SmsResponder::ok('Any message');
        $output = ob_get_clean();
        $this->assertStringContainsString("\nVisit https://example.com/walk/site/", $output);
        $this->assertEquals(1, substr_count($output, 'https://example.com/walk/site/'));
    }

    public function testFooterDbFallbackWhenEnvMissing()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        // Ensure SITE_URL resolves empty via env() for this test (override .env)
        $prev = $_ENV['SITE_URL'] ?? null;
        $_ENV['SITE_URL'] = '';

        // Define a lightweight pdo() that SmsResponder's config helper will use
        if (!function_exists('pdo')) {
            function pdo(): PDO {
                static $mem = null;
                if ($mem instanceof PDO) return $mem;
                $mem = new PDO('sqlite::memory:');
                $mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $mem->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)');
                $stmt = $mem->prepare('INSERT OR REPLACE INTO settings(key,value,updated_at) VALUES(?,?,datetime("now"))');
                $stmt->execute(['site.url', 'https://example.com/walk/site/']);
                return $mem;
            }
        }

        SmsResponder::ok('Recorded 100 for Test on today.');
        $output = ob_get_clean();
        $this->assertStringContainsString('Visit https://example.com/walk/site/', $output);
        $this->assertStringContainsString('text &quot;walk&quot; or &quot;menu&quot; for menu', $output);

        // Restore env
        if ($prev === null) unset($_ENV['SITE_URL']); else $_ENV['SITE_URL'] = $prev;
    }
}
