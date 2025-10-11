<?php

use PHPUnit\Framework\TestCase;
use App\Http\Responders\SmsResponder;

class SmsResponderTest extends TestCase
{
    protected function setUp(): void
    {
        // Start a fresh output buffer for each test
        ob_start();
    }

    protected function tearDown(): void
    {
        if (ob_get_level() > 0) {
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
        $this->assertStringContainsString('<Response><Message>Test message</Message></Response>', $output);
    }

    public function testOkResponseWithoutTwilioHeaderReturnsJson()
    {
        unset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);

        SmsResponder::ok('Test message');

        $output = ob_get_clean();
        $json = json_decode($output, true);
        $this->assertEquals(['ok' => true, 'message' => 'Test message'], $json);
    }

    public function testErrorResponseWithTwilioHeaderReturnsXml()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        SmsResponder::error('Error message', 'test_error', 400);

        $output = ob_get_clean();
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContainsString('<Response><Message>Error message</Message></Response>', $output);
    }

    public function testErrorResponseWithoutTwilioHeaderReturnsJson()
    {
        unset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);

        SmsResponder::error('Error message', 'test_error', 400);

        $output = ob_get_clean();
        $json = json_decode($output, true);
        $this->assertEquals(['error' => 'test_error', 'message' => 'Error message'], $json);
    }

    public function testExplicitFormatQueryParamOverridesHeader()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_GET['format'] = 'json';

        SmsResponder::ok('Test message');

        $output = ob_get_clean();
        $json = json_decode($output, true);
        $this->assertEquals(['ok' => true, 'message' => 'Test message'], $json);
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
}
