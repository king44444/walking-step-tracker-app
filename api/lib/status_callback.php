<?php
// File: api/status_callback.php
// Receives Twilio status callbacks for outbound/inbound messages.
// Expects application/x-www-form-urlencoded POST.
// Verifies X-Twilio-Signature if TWILIO_AUTH_TOKEN is set.
// Logs events into SQLite: data/walkweek.sqlite (table DDL below).

declare(strict_types=1);

// ----- config -----
$dbFile = __DIR__ . '/../data/walkweek.sqlite';
$authToken = getenv('TWILIO_AUTH_TOKEN') ?: ''; // set in web server env

// ----- helpers -----
function http_response(int $code, string $body = ''): void {
    http_response_code($code);
    if ($body !== '') header('Content-Type: text/plain; charset=utf-8');
    echo $body;
    exit;
}

require_once __DIR__ . '/../common_sig.php';

function verify_twilio_signature(string $authToken): bool {
    // If no token configured, skip verification (not recommended for prod).
    if ($authToken === '') return true;

    $signature = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

    // Allow in test mode or trusted local addrs when no header present
    if (twilio_should_skip() && $signature === '') return true;

    $info = twilio_verify($_POST, $signature, $authToken);
    if (getenv('TWILIO_SIG_DEBUG') === '1') {
        error_log('SIG url=' . $info['url'] . ' match=' . (int)$info['match'] . ' hdr=' . $info['header'] . ' exp=' . $info['expected'] . ' post=' . json_encode($_POST, JSON_UNESCAPED_SLASHES));
    }

    // Constant-time compare result included in $info['match']
    return $info['match'];
}

// ----- verify method -----
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response(405, 'Method Not Allowed');
}

// ----- verify signature -----
if (!verify_twilio_signature($authToken)) {
    http_response(403, 'Invalid signature');
}

// ----- collect fields (Twilio names) -----
$nowUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

$record = [
    'message_sid'     => $_POST['MessageSid']     ?? $_POST['SmsSid'] ?? null,
    'message_status'  => $_POST['MessageStatus']  ?? $_POST['SmsStatus'] ?? null,
    'to_number'       => $_POST['To']             ?? null,
    'from_number'     => $_POST['From']           ?? null,
    'error_code'      => $_POST['ErrorCode']      ?? null,
    'error_message'   => $_POST['ErrorMessage']   ?? null,
    'messaging_service_sid' => $_POST['MessagingServiceSid'] ?? null,
    'account_sid'     => $_POST['AccountSid']     ?? null,
    'api_version'     => $_POST['ApiVersion']     ?? null,
    'raw_payload'     => json_encode($_POST, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    'received_at_utc' => $nowUtc,
];

// ----- store to SQLite -----
try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    // Create table if missing
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS message_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            message_sid TEXT UNIQUE,
            message_status TEXT,
            to_number TEXT,
            from_number TEXT,
            error_code TEXT,
            error_message TEXT,
            messaging_service_sid TEXT,
            account_sid TEXT,
            api_version TEXT,
            raw_payload TEXT,
            received_at_utc TEXT
        );
        CREATE INDEX IF NOT EXISTS idx_message_status_sid ON message_status(message_sid);
        CREATE INDEX IF NOT EXISTS idx_message_status_status ON message_status(message_status);
    ");

    // Upsert by message_sid
    $stmt = $pdo->prepare("
        INSERT INTO message_status (
            message_sid, message_status, to_number, from_number, error_code, error_message,
            messaging_service_sid, account_sid, api_version, raw_payload, received_at_utc
        ) VALUES (
            :message_sid, :message_status, :to_number, :from_number, :error_code, :error_message,
            :messaging_service_sid, :account_sid, :api_version, :raw_payload, :received_at_utc
        )
        ON CONFLICT(message_sid) DO UPDATE SET
            message_status=excluded.message_status,
            to_number=excluded.to_number,
            from_number=excluded.from_number,
            error_code=excluded.error_code,
            error_message=excluded.error_message,
            messaging_service_sid=excluded.messaging_service_sid,
            account_sid=excluded.account_sid,
            api_version=excluded.api_version,
            raw_payload=excluded.raw_payload,
            received_at_utc=excluded.received_at_utc
    ");
    $stmt->execute($record);
} catch (Throwable $e) {
    // Fail closed but acknowledge to Twilio to avoid retries storms
    error_log('status_callback error: ' . $e->getMessage());
    http_response(200); // acknowledge anyway
}

// Twilio expects 200 with no body
http_response(200);
