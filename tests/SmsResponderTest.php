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
        $_ENV['SITE_URL'] = 'https://example.com/walk';

        SmsResponder::ok('Recorded 1,240 for Mike on today.');
        $output = ob_get_clean();
        $this->assertStringContainsString('Recorded 1,240 for Mike on today.', $output);
        $this->assertStringContainsString('Visit https://example.com/walk', $output);
        $this->assertStringContainsString('text &quot;info&quot; or &quot;walk&quot; for menu', $output);
    }

    public function testFooterAppendedForDayNumber()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_ENV['SITE_URL'] = 'https://example.com/walk';

        SmsResponder::ok('Recorded 1,240 for Mike on tuesday.');
        $output = ob_get_clean();
        $this->assertStringContainsString('Recorded 1,240 for Mike on tuesday.', $output);
        $this->assertStringContainsString('Visit https://example.com/walk', $output);
    }

    public function testNoDuplicateWhenUrlAlreadyPresent()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_ENV['SITE_URL'] = 'https://example.com/walk';

        $msg = 'Recorded 1,240 for Mike on today. Visit https://example.com/walk — text "info" or "walk" for menu.';
        SmsResponder::ok($msg);
        $output = ob_get_clean();
        // The message should not contain the URL twice
        $this->assertEquals(1, substr_count($output, 'https://example.com/walk'));
    }
}
