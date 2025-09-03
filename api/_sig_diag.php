<?php
declare(strict_types=1);

/**
 * Diagnostic endpoint for Twilio signature verification.
 * Only active when TWILIO_SIG_DEBUG=== '1'.
 * Requires either ?once=<ts> OR header X-Diag-Token matching env TWILIO_DIAG_TOKEN.
 *
 * Outputs JSON:
 * {
 *  "url_seen": "...",
 *  "post_sorted": [ "From:+1801...", "MessageSid:...", ... ],
 *  "joined": "...",
 *  "expected_sig": "...",
 *  "header_sig": "...",
 *  "match": true|false
 * }
 */

if (getenv('TWILIO_SIG_DEBUG') !== '1') {
  http_response_code(404);
  echo 'Not found';
  exit;
}

$once = $_GET['once'] ?? null;
$diagHeader = $_SERVER['HTTP_X_DIAG_TOKEN'] ?? '';
$diagToken = getenv('TWILIO_DIAG_TOKEN') ?: '';

if (!$once && ($diagToken === '' || $diagHeader !== $diagToken)) {
  http_response_code(403);
  echo 'Forbidden';
  exit;
}

require_once __DIR__ . '/common_sig.php';

$headerSig = $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? '';

$info = twilio_verify($_POST, $headerSig, getenv('TWILIO_AUTH_TOKEN') ?: '');

// build post_sorted array "Key:Value"
$post_sorted = [];
$keys = array_keys($_POST);
sort($keys, SORT_STRING);
foreach ($keys as $k) {
  $post_sorted[] = $k . ':' . ($_POST[$k] ?? '');
}

$out = [
  'url_seen' => $info['url'],
  'post_sorted' => $post_sorted,
  'joined' => $info['joined'],
  'expected' => $info['expected'],
  'header_sig' => $info['header'] ?? '',
  'match' => (bool)$info['match'],
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
