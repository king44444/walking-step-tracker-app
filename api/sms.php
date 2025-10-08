<?php
// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');
require __DIR__.'/util.php';
require __DIR__.'/lib/env.php';
require __DIR__.'/lib/phone.php';
require __DIR__.'/lib/dates.php';
require __DIR__.'/lib/entries.php';
require __DIR__.'/lib/twilio.php';
require __DIR__.'/lib/config.php';
require __DIR__.'/lib/ai_sms.php';
require __DIR__.'/lib/outbound.php';
// Content-Type set dynamically below (JSON for internal/testing, TwiML for Twilio)

$secret = env('INTERNAL_API_SECRET','');
$is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1']);
$has_secret = $secret !== '' && (isset($_SERVER['HTTP_X_INTERNAL_SECRET']) && hash_equals($secret, $_SERVER['HTTP_X_INTERNAL_SECRET']));
$is_internal = $is_local || $has_secret;

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;
use App\Support\Tx;
$pdo = DB::pdo();
$from = $_POST['From'] ?? '';
$body = trim($_POST['Body'] ?? '');
$e164 = to_e164($from);
$now  = now_in_tz();
$createdAt = $now->format(DateTime::ATOM);

$insAudit = $pdo->prepare("INSERT INTO sms_audit(created_at,from_number,raw_body,parsed_day,parsed_steps,resolved_week,resolved_day,status) VALUES(?,?,?,?,?,?,?,?)");

$audit_exec = function(array $params) use ($insAudit) {
  with_file_lock(__DIR__ . '/../data/sqlite.write.lock', function() use ($insAudit, $params) {
    for ($i = 0; $i < 5; $i++) {
      try { $insAudit->execute($params); return; }
      catch (PDOException $e) {
        $m = $e->getMessage();
        if (stripos($m, 'locked') !== false || stripos($m, 'SQLITE_BUSY') !== false) { usleep(200000); continue; }
        throw $e;
      }
    }
  });
};


$auth = env('TWILIO_AUTH_TOKEN','');
$is_twilio = isset($_SERVER['HTTP_X_TWILIO_SIGNATURE']);
if (!$is_internal && $auth !== '') {
  $url = (isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
  $ok  = verify_twilio_signature($auth, $url, $_POST, $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ?? null);
  if (!$ok) { $audit_exec([$createdAt,$e164,$body,null,null,null,null,'bad_signature']); http_response_code(403); echo json_encode(['error'=>'bad_signature']); exit; }
}

if (!$e164 || $body==='') {
  $audit_exec([$createdAt,$from,$body,null,null,null,null,'bad_request']);
  $errMsg = 'Sorry, we could not read your number or message. Please try again.';
  if (!$is_internal && $is_twilio) { header('Content-Type: text/xml; charset=utf-8'); http_response_code(200); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')."</Message></Response>"; }
  else { header('Content-Type: application/json; charset=utf-8'); http_response_code(400); echo json_encode(['error'=>'bad_request','message'=>$errMsg]); }
  exit;
}

# rate limit 60s per number on last ok
$cut = (clone $now)->modify('-60 seconds')->format(DateTime::ATOM);
$stRL = $pdo->prepare("SELECT 1 FROM sms_audit WHERE from_number=? AND status='ok' AND created_at>=? LIMIT 1");
$stRL->execute([$e164, $cut]);
if ($stRL->fetchColumn()) {
  $audit_exec([$createdAt,$e164,$body,null,null,null,null,'rate_limited']);
  $errMsg = 'Got it! Please wait a minute before sending another update.';
  if (!$is_internal && $is_twilio) { header('Content-Type: text/xml; charset=utf-8'); http_response_code(200); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')."</Message></Response>"; }
  else { header('Content-Type: application/json; charset=utf-8'); http_response_code(429); echo json_encode(['error'=>'rate_limited','message'=>$errMsg]); }
  exit;
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
  $errMsg = "Please send one number like 12345 or 'Tue 12345'.";
  if (!$is_internal && $is_twilio) { header('Content-Type: text/xml; charset=utf-8'); http_response_code(200); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')."</Message></Response>"; }
  else { header('Content-Type: application/json; charset=utf-8'); http_response_code(400); echo json_encode(['error'=>'too_many_numbers','message'=>$errMsg]); }
  exit;
}

$dayOverride = null; $steps = null;
if (preg_match('/^\s*([A-Za-z]{3,9})\b\D*([0-9]{2,})\s*$/', $body_norm, $m)) { $dayOverride=$m[1]; $steps=intval($m[2]); }
elseif (preg_match('/^\s*([0-9]{2,})\s*$/', $body_norm, $m)) { $steps=intval($m[1]); }
else { $audit_exec([$createdAt,$e164,$raw_body,null,null,null,null,'no_steps']); $errMsg = "Please send one number like 12345 or 'Tue 12345'."; if (!$is_internal && $is_twilio) { header('Content-Type: text/xml; charset=utf-8'); http_response_code(200); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')."</Message></Response>"; } else { header('Content-Type: application/json; charset=utf-8'); http_response_code(400); echo json_encode(['error'=>'no_steps','message'=>$errMsg]); } exit; }

if ($steps < 0 || $steps > 200000) { $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'invalid_steps']); $errMsg='That number looks off. Try a value between 0 and 200,000.'; if (!$is_internal && $is_twilio) { header('Content-Type: text/xml; charset=utf-8'); http_response_code(200); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')."</Message></Response>"; } else { header('Content-Type: application/json; charset=utf-8'); http_response_code(400); echo json_encode(['error'=>'invalid_steps','message'=>$errMsg]); } exit; }

$stU = $pdo->prepare("SELECT name FROM users WHERE phone_e164=?");
$stU->execute([$e164]);
$u = $stU->fetch(PDO::FETCH_ASSOC);
if (!$u) { $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'unknown_number']); $errMsg='We do not recognize this number. Ask admin to enroll your phone.'; if (!$is_internal && $is_twilio) { header('Content-Type: text/xml; charset=utf-8'); http_response_code(200); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')."</Message></Response>"; } else { header('Content-Type: application/json; charset=utf-8'); http_response_code(404); echo json_encode(['error'=>'unknown_number','message'=>$errMsg]); } exit; }
$name = $u['name'];

$dayCol = resolve_target_day($now, $dayOverride);
if (!$dayCol) { $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,null,'bad_day']); $errMsg='Unrecognized day. Use Mon..Sat or leave it out.'; if (!$is_internal && $is_twilio) { header('Content-Type: text/xml; charset=utf-8'); http_response_code(200); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')."</Message></Response>"; } else { header('Content-Type: application/json; charset=utf-8'); http_response_code(400); echo json_encode(['error'=>'bad_day','message'=>$errMsg]); } exit; }

$week = resolve_active_week($pdo);
if (!$week) { $audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,null,$dayCol,'no_active_week']); $errMsg='No active week to record to. Please try again later.'; if (!$is_internal && $is_twilio) { header('Content-Type: text/xml; charset=utf-8'); http_response_code(200); echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response><Message>".htmlspecialchars($errMsg, ENT_QUOTES, 'UTF-8')."</Message></Response>"; } else { header('Content-Type: application/json; charset=utf-8'); http_response_code(404); echo json_encode(['error'=>'no_active_week','message'=>$errMsg]); } exit; }

Tx::with(function(\PDO $pdo) use ($week, $name, $dayCol, $steps) {
  upsert_steps($pdo, $week, $name, $dayCol, $steps);
});
$audit_exec([$createdAt,$e164,$raw_body,$dayOverride,$steps,$week,$dayCol,'ok']);

$aiOn = get_setting('ai_enabled');
if ($aiOn === '1') {
  // Rate limit per user: 1 gen / 2 minutes
  try {
    $stUId = $pdo->prepare('SELECT id, phone_e164 FROM users WHERE name = :n');
    $stUId->execute([':n'=>$name]);
    $usr = $stUId->fetch(PDO::FETCH_ASSOC);
    $userId = (int)($usr['id'] ?? 0);
    $toPhone = (string)($usr['phone_e164'] ?? '');

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_stats(user_id INTEGER PRIMARY KEY, last_ai_at TEXT)");
    $last = $pdo->prepare('SELECT last_ai_at FROM user_stats WHERE user_id = ?');
    $last->execute([$userId]);
    $lastAt = (string)($last->fetchColumn() ?: '');
    $can = true;
    if ($lastAt !== '') {
      $diff = time() - strtotime($lastAt);
      if ($diff < 120) $can = false;
    }
    if ($can) {
      $gen = generate_ai_sms_reply($name, $raw_body, ['week_label' => $label]);
      $content = (string)$gen['content'];
      $model   = (string)$gen['model'];
      $rawJson = json_encode($gen['raw'], JSON_UNESCAPED_SLASHES);
      $cost    = $gen['cost_usd'];
      $ins = $pdo->prepare("INSERT INTO ai_messages(type,scope_key,user_id,week,content,model,prompt_hash,approved_by,created_at,sent_at,provider,raw_json,cost_usd)
                            VALUES('sms',NULL,:uid,:wk,:body,:model,NULL,NULL,datetime('now'),NULL,:prov,:raw,:cost)");
      $ins->execute([':uid'=>$userId, ':wk'=>$week, ':body'=>$content, ':model'=>$model, ':prov'=>'openrouter', ':raw'=>$rawJson, ':cost'=>$cost]);
      $pdo->prepare('INSERT INTO user_stats(user_id,last_ai_at) VALUES(:u, datetime(\'now\')) ON CONFLICT(user_id) DO UPDATE SET last_ai_at = excluded.last_ai_at')->execute([':u'=>$userId]);

      $auto = get_setting('ai_autosend');
      if ($auto === '1' && $toPhone !== '') {
        // Auto-approve and send immediately
        $lastId = (int)$pdo->query('SELECT last_insert_rowid()')->fetchColumn();
        $pdo->prepare('UPDATE ai_messages SET approved_by = :u WHERE id = :id')->execute([':u'=>'auto', ':id'=>$lastId]);
        try { send_outbound_sms($toPhone, $content); $pdo->prepare('UPDATE ai_messages SET sent_at = datetime(\'now\') WHERE id = :id')->execute([':id'=>$lastId]); } catch (Throwable $e) { /* leave unsent */ }
      }
    }
  } catch (Throwable $e) {
    // Swallow AI errors; core SMS path must succeed
    error_log('sms.php AI error: ' . $e->getMessage());
  }
}

$noonRule = !$dayOverride ? (intval($now->format('H'))<12 ? 'yesterday' : 'today') : strtolower($dayCol);
$msg = "Recorded ".number_format($steps)." for $name on $noonRule.";

  // If this is a real Twilio webhook (signature header present) and not an internal call,
  // respond with TwiML so Twilio replies via SMS to the sender.
  if (!$is_internal && $is_twilio) {
    header('Content-Type: text/xml; charset=utf-8');
    echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<Response><Message>" . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</Message></Response>";
  } else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'message'=>$msg,'name'=>$name,'steps'=>$steps,'day'=>$noonRule]);
  }
