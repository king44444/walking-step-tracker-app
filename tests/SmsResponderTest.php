<?php

use PHPUnit\Framework\TestCase;
use App\Http\Responders\SmsResponder;

class SmsResponderTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean up any existing output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();
    }

    protected function tearDown(): void
    {
        // Clean up output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
    }

    public function testOkResponseWithTwilioHeaderReturnsXml()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        SmsResponder::ok('Test message');

        $output = ob_get_clean();
        $this->assertStringContains('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContains('<Response><Message>Test message</Message></Response>', $output);
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
        $this->assertStringContains('<?xml version="1.0" encoding="UTF-8"?>', $output);
        $this->assertStringContains('<Response><Message>Error message</Message></Response>', $output);
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
        $this->assertStringContains('<?xml version="1.0" encoding="UTF-8"?>', $output);
    }

    public function testXmlEscaping()
    {
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        SmsResponder::ok('Message with <tags> & "quotes"');

        $output = ob_get_clean();
        $this->assertStringContains('Message with <tags> & "quotes"', $output);
    }
}
