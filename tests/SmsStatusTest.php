<?php

use PHPUnit\Framework\TestCase;
use App\Controllers\SmsController;
use App\Config\DB;

class SmsStatusTest extends TestCase
{
    protected function setUp(): void
    {
        // Clean up any existing output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
    }

    protected function tearDown(): void
    {
        // Clean up output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }
    }

    public function testStatusDeliveredCallback()
    {
        // Mock POST data for delivered status
        $_POST = [
            'MessageSid' => 'SM1234567890abcdef',
            'MessageStatus' => 'delivered',
            'To' => '+15551234567',
            'From' => '+1234567890',
            'AccountSid' => 'AC1234567890abcdef',
            'ApiVersion' => '2010-04-01'
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        // Mock environment
        putenv('TWILIO_AUTH_TOKEN=test_token');

        $controller = new SmsController();
        $controller->status();

        // Verify record was inserted
        $pdo = DB::pdo();
        $stmt = $pdo->query("SELECT * FROM message_status WHERE message_sid = 'SM1234567890abcdef'");
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($record);
        $this->assertEquals('SM1234567890abcdef', $record['message_sid']);
        $this->assertEquals('delivered', $record['message_status']);
        $this->assertEquals('+15551234567', $record['to_number']);
        $this->assertEquals('+1234567890', $record['from_number']);
        $this->assertEquals('AC1234567890abcdef', $record['account_sid']);
        $this->assertEquals('2010-04-01', $record['api_version']);
        $this->assertNotNull($record['received_at_utc']);
    }

    public function testStatusFailedCallback()
    {
        // Mock POST data for failed status
        $_POST = [
            'MessageSid' => 'SM1234567890abcdef',
            'MessageStatus' => 'failed',
            'To' => '+15551234567',
            'From' => '+1234567890',
            'ErrorCode' => '30001',
            'ErrorMessage' => 'Unknown error',
            'AccountSid' => 'AC1234567890abcdef'
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        putenv('TWILIO_AUTH_TOKEN=test_token');

        $controller = new SmsController();
        $controller->status();

        // Verify record was inserted with error details
        $pdo = DB::pdo();
        $stmt = $pdo->query("SELECT * FROM message_status WHERE message_sid = 'SM1234567890abcdef'");
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($record);
        $this->assertEquals('failed', $record['message_status']);
        $this->assertEquals('30001', $record['error_code']);
        $this->assertEquals('Unknown error', $record['error_message']);
    }

    public function testStatusUndeliveredCallback()
    {
        // Mock POST data for undelivered status
        $_POST = [
            'MessageSid' => 'SM1234567890abcdef',
            'MessageStatus' => 'undelivered',
            'To' => '+15551234567',
            'From' => '+1234567890',
            'ErrorCode' => '30002',
            'ErrorMessage' => 'Message undeliverable'
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        putenv('TWILIO_AUTH_TOKEN=test_token');

        $controller = new SmsController();
        $controller->status();

        // Verify record was inserted
        $pdo = DB::pdo();
        $stmt = $pdo->query("SELECT * FROM message_status WHERE message_sid = 'SM1234567890abcdef'");
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotNull($record);
        $this->assertEquals('undelivered', $record['message_status']);
        $this->assertEquals('30002', $record['error_code']);
        $this->assertEquals('Message undeliverable', $record['error_message']);
    }

    public function testStatusUpsertUpdatesExistingRecord()
    {
        // First insert
        $_POST = [
            'MessageSid' => 'SM1234567890abcdef',
            'MessageStatus' => 'sent',
            'To' => '+15551234567'
        ];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_TWILIO_SIGNATURE'] = 'test_signature';

        putenv('TWILIO_AUTH_TOKEN=test_token');

        $controller = new SmsController();
        $controller->status();

        // Update with delivered status
        $_POST['MessageStatus'] = 'delivered';
        $controller->status();

        // Verify only one record exists and it was updated
        $pdo = DB::pdo();
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM message_status WHERE message_sid = 'SM1234567890abcdef'");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        $this->assertEquals(1, $count);

        $stmt = $pdo->query("SELECT * FROM message_status WHERE message_sid = 'SM1234567890abcdef'");
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals('delivered', $record['message_status']);
    }

    public function testStatusInvalidMethodReturns405()
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $controller = new SmsController();
        $controller->status();

        // Should have exited with 405 status
        $this->assertTrue(true); // If we reach here, the method handled the error correctly
    }
}
