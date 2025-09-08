<?php
require __DIR__.'/util.php';
require __DIR__.'/lib/env.php';
require __DIR__.'/lib/phone.php';
require __DIR__.'/lib/dates.php';
require __DIR__.'/lib/entries.php';
require __DIR__.'/lib/twilio.php';
header('Content-Type: text/plain; charset=utf-8');

$secret = env('INTERNAL_API_SECRET','');
$is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1']);
$has_secret = $secret !== '' && (isset($_SERVER['HTTP_X_INTERNAL_SECRET']) && hash_equals($secret, $_SERVER['HTTP_X_INTERNAL_SECRET']));
$is_internal = $is_local || $has_secret;

$pdo = pdo();
$from = $_POST['From'] ?? '';
$body = trim($_POST['Body'] ?? '');
$e164 = to_e164($from);
$now  = now_in_tz();
$createdAt = $now->format(DateTime::ATOM);

$insAudit = $pdo->prepare("INSERT INTO sms_audit(created_at,from_number,raw_body,parsed_day,parsed_steps,resolved_week,resolved_day,status) VALUES(?,?,?,?,?,?,?,?)");

$audit_exec = function(array $params) use ($insAudit) {
  for ($i = 0; $i < 5; $i++) {
    try { $insAudit->execute($params); return; }
    catch (PDOException $e) {
      $m = $e->getMessage();
      if (stripos($m, 'locked') !== false || stripos($m, 'SQLITE_BUSY') !== false) { usleep(200000); continue; }
      throw $e;
    }
  }
  // If we exhaust retries, let execution continue and bubble an error.
};

function with_txn_retry(PDO $pdo, callable $fn) {
  for ($i = 0; $i < 5; $i++) {
    try {
      $pdo->exec('BEGIN IMMEDIATE');
      $fn();
      $pdo->exec('COMMIT');
      return;
    } catch (PDOException $e) {
      try { $pdo->exec('ROLLBACK'); } catch (Exception $_) {}
      $m = $e->getMessage();
      if (stripos($m, 'locked') !== false || stripos($m, 'SQLITE_BUSY') !== false) { usleep(200000); continue; }
      throw $e;
    }
  }
  throw new RuntimeException('DB busy after retries');
}

$auth = env('TWILIO_AUTH_TOKEN','');
if (!$is_internal && $auth !== '') {
  $url = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
  $ok  = verify_twilio_signature($auth, $url, $_POST, $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? null);
  if (!$ok) { $audit_exec([$createdAt,$e164,$body,null,null,null,null,'bad_signature']); http_response_code(403); echo "Forbidden."; exit; }
}

if (!$e164 || $body==='') {
  $audit_exec([$createdAt,$from,$body,null,null,null,null,'bad_request']);
  http_response_code(400); echo "Bad request."; exit;
}

# rate limit 60s per number on last ok
$cut = (clone $now)->modify('-60 seconds')->format(DateTime::ATOM);
$stRL = $pdo->prepare("SELECT 1 FROM sms_audit WHERE from_number=? AND status='ok' AND created_at>=? LIMIT 1");
$stRL->execute([$e164, $cut]);
if ($stRL->fetchColumn()) {
  $audit_exec([$createdAt,$e164,$body,null,null,null,null,'rate_limited']);
  http_response_code(429); echo "Slow down. Try again in a minute."; exit;
}

# parse input with single numeric group rule
// Preserve original text for audits
$raw_body = $body;
// Normalize thousands separators that appear between digit groups (commas, dots, and various spaces).
// This converts "12,345", "12 345", "12.345" -> "12345" but does NOT remove commas that separate distinct numbers like "3, 4".
$body_norm = preg_replace('/(?<=\d)[\p{Zs}\x{00A0}\x{202F},\.](?=\d{3}\b)/u', '', $body);
$body_norm = trim($body_norm);

if (preg_match_all('/\d+/', $body_norm, $mm) && count($mm[0]) > 1) {
  $audit_exec([$createdAt,$e164,$raw_body,null,null,null,null,'too_many_numbers']);
  http_response_code(400); echo "Send one number like 12345 or 'Tue 12345'."; exit;
}

$dayOverride = null; $steps = null;
if (preg_match('/^\s*([A-Za-z]{3,9})\b\D*([0-9]{2,})\s*$/', $body_norm, $m)) { $dayOverride=$m[1]; $steps=intval($m[2]); }
elseif (preg_match('/^\s*([0-9]{2,})\s*$/', $body_norm, $m)) { $steps=intval($m[1]); }
else { $audit_exec([$createdAt,$e164,$raw_body,null,null,null,null,'no_steps']); http_response_code(400); echo "Send one number like 12345 or 'Tue 12345'."; exit; }

if ($steps < 0 || $steps > 200000) { $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'invalid_steps']); http_response_code(400); echo "Invalid steps."; exit; }

$stU = $pdo->prepare("SELECT name FROM users WHERE phone_e164=?");
$stU->execute([$e164]);
$u = $stU->fetch(PDO::FETCH_ASSOC);
if (!$u) { $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'unknown_number']); echo "Number not recognized. Ask admin to enroll your phone."; exit; }
$name = $u['name'];

$dayCol = resolve_target_day($now, $dayOverride);
if (!$dayCol) { $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'bad_day']); http_response_code(400); echo "Unrecognized day. Use Mon..Sat or leave it out."; exit; }

$week = resolve_active_week($pdo);
if (!$week) { $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,$dayCol,'no_active_week']); echo "No active week."; exit; }

with_txn_retry($pdo, function() use ($pdo, $week, $name, $dayCol, $steps) {
  upsert_steps($pdo, $week, $name, $dayCol, $steps);
});
$audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,$week,$dayCol,'ok']);

$noonRule = !$dayOverride ? (intval($now->format('H'))<12 ? 'yesterday' : 'today') : strtolower($dayCol);
echo "Recorded ".number_format($steps)." for $name on $noonRule.";
