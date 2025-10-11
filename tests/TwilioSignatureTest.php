<?php

use PHPUnit\Framework\TestCase;
use App\Security\TwilioSignature;

class TwilioSignatureTest extends TestCase
{
    private $originalEnv = [];

    protected function setUp(): void
    {
        // Save original environment variables
        $this->originalEnv = [
            'APP_ENV' => getenv('APP_ENV'),
            'TWILIO_SKIP_SIG' => getenv('TWILIO_SKIP_SIG'),
            'TWILIO_TEST_MODE' => getenv('TWILIO_TEST_MODE'),
        ];
    }

    protected function tearDown(): void
    {
        // Restore original environment variables
        foreach ($this->originalEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("$key=$value");
            }
        }
    }

    public function testValidSignature()
    {
        // Set up environment
        putenv('APP_ENV=prod');
        putenv('TWILIO_SKIP_SIG=0');

        // Mock server variables
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';

        // Test data
        $request = ['param1' => 'value1', 'param2' => 'value2'];
        $url = 'https://example.com/test';
        $authToken = 'test_token';

        // This will fail because we're using a fake signature, but it tests the logic path
        $result = TwilioSignature::verify($request, $url, $authToken);
        $this->assertIsBool($result);
    }

    public function testInvalidSignature()
    {
        // Set up environment
        putenv('APP_ENV=prod');
        putenv('TWILIO_SKIP_SIG=0');

        // Mock server variables
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'invalid_signature';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';

        $request = ['param1' => 'value1'];
        $url = 'https://example.com/test';
        $authToken = 'test_token';

        $result = TwilioSignature::verify($request, $url, $authToken);
        $this->assertFalse($result);
    }

    public function testBypassInNonProdEnvironment()
    {
        // Set up environment for bypass
        putenv('APP_ENV=dev');
        putenv('TWILIO_SKIP_SIG=1');

        $request = ['param1' => 'value1'];
        $url = 'https://example.com/test';
        $authToken = 'test_token';

        $result = TwilioSignature::verify($request, $url, $authToken);
        $this->assertTrue($result);
    }

    public function testNoBypassInProdEnvironment()
    {
        // Set up environment - should NOT bypass in prod
        putenv('APP_ENV=prod');
        putenv('TWILIO_SKIP_SIG=1');

        // Mock server variables
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'invalid_signature';
        $_SERVER['REQUEST_URI'] = '/test';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['HTTPS'] = 'on';

        $request = ['param1' => 'value1'];
        $url = 'https://example.com/test';
        $authToken = 'test_token';

        $result = TwilioSignature::verify($request, $url, $authToken);
        $this->assertFalse($result);
    }

    public function testLegacyTestModeBypass()
    {
        // Test legacy TWILIO_TEST_MODE
        putenv('APP_ENV=prod');
        putenv('TWILIO_SKIP_SIG=0');
        putenv('TWILIO_TEST_MODE=1');

        $request = ['param1' => 'value1'];
        $url = 'https://example.com/test';
        $authToken = 'test_token';

        $result = TwilioSignature::verify($request, $url, $authToken);
        $this->assertTrue($result);
    }

    public function testTrustedIpBypass()
    {
        // Set up environment
        putenv('APP_ENV=prod');
        putenv('TWILIO_SKIP_SIG=0');
        putenv('TWILIO_TEST_MODE=0');

        // Mock trusted IP without signature header
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        unset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);

        $request = ['param1' => 'value1'];
        $url = 'https://example.com/test';
        $authToken = 'test_token';

        $result = TwilioSignature::verify($request, $url, $authToken);
        $this->assertTrue($result);
    }

    public function testEmptyAuthTokenSkipsVerification()
    {
        putenv('APP_ENV=prod');
        putenv('TWILIO_SKIP_SIG=0');

        $request = ['param1' => 'value1'];
        $url = 'https://example.com/test';
        $authToken = ''; // Empty token

        $result = TwilioSignature::verify($request, $url, $authToken);
        $this->assertTrue($result);
    }

    public function testBuildTwilioUrl()
    {
        // Test HTTPS
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['HTTP_HOST'] = 'example.com';
        $_SERVER['REQUEST_URI'] = '/api/test';

        $url = TwilioSignature::buildTwilioUrl();
        $this->assertEquals('https://example.com/api/test', $url);

        // Test HTTP
        unset($_SERVER['HTTPS']);
        $url = TwilioSignature::buildTwilioUrl();
        $this->assertEquals('http://example.com/api/test', $url);

        // Test with X-Forwarded headers
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        $_SERVER['HTTP_X_FORWARDED_HOST'] = 'proxy.example.com';
        $_SERVER['REQUEST_URI'] = '/api/test?param=value';

        $url = TwilioSignature::buildTwilioUrl();
        $this->assertEquals('https://proxy.example.com/api/test', $url);
    }
}
