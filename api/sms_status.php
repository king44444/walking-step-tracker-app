<?php
// File: api/sms_status.php
// Twilio Messaging "Delivery Status Callback" webhook.
// Expects form-POST. Verifies X-Twilio-Signature if TWILIO_AUTH_TOKEN is set.
// Logs to SQLite: data/walkweek.sqlite (table message_status).

declare(strict_types=1);

// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');

$dbFile = __DIR__ . '/../data/walkweek.sqlite';
$authToken = getenv('TWILIO_AUTH_TOKEN') ?: '';

function done(int $code=200, string $body=''): void {
  http_response_code($code);
  if ($body !== '') header('Content-Type: text/plain; charset=utf-8');
  echo $body;
  exit;
}

require_once __DIR__ . '/common_sig.php';

function verify_sig(string $authToken): bool {
  if ($authToken === '') return true;
  $hdr = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';
  // Allow in test mode or trusted local addrs when no header present
  if (twilio_should_skip() && $hdr === '') return true;

  $info = twilio_verify($_POST, $hdr, $authToken);
  if (getenv('TWILIO_SIG_DEBUG') === '1') {
    error_log('SIG url=' . $info['url'] . ' match=' . (int)$info['match'] . ' hdr=' . $info['header'] . ' exp=' . $info['expected'] . ' post=' . json_encode($_POST, JSON_UNESCAPED_SLASHES));
  }
  return $info['match'];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') done(405, 'Method Not Allowed');
if (!verify_sig($authToken)) done(403, 'Invalid signature');

$now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');

$rec = [
  ':message_sid' => $_POST['MessageSid'] ?? $_POST['SmsSid'] ?? null,
  ':message_status' => $_POST['MessageStatus'] ?? $_POST['SmsStatus'] ?? null, // queued|sent|delivered|undelivered|failed
  ':to_number' => $_POST['To'] ?? null,
  ':from_number' => $_POST['From'] ?? null,
  ':error_code' => $_POST['ErrorCode'] ?? null,
  ':error_message' => $_POST['ErrorMessage'] ?? null,
  ':messaging_service_sid' => $_POST['MessagingServiceSid'] ?? null,
  ':account_sid' => $_POST['AccountSid'] ?? null,
  ':api_version' => $_POST['ApiVersion'] ?? null,
  ':raw_payload' => json_encode($_POST, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
  ':received_at_utc' => $now,
];

try {
  $pdo = new PDO('sqlite:' . $dbFile, null, null, [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
  ]);
  $pdo->exec('PRAGMA foreign_keys=ON');

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
  $stmt->execute($rec);
} catch (Throwable $e) {
  error_log('sms_status error: '.$e->getMessage());
  // still acknowledge to stop Twilio retries
}

done(200);
