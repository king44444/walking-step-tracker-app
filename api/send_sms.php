<?php
declare(strict_types=1);

// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');
require __DIR__.'/lib/env.php';
require __DIR__.'/util.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = pdo();

// Internal auth
$secret = env('INTERNAL_API_SECRET','');
$hdr = $_SERVER['HTTP_X_INTERNAL_SECRET'] ?? '';
if ($secret === '' || !hash_equals($secret, $hdr)) { http_response_code(403); echo json_encode(['error'=>'forbidden']); exit; }

// Read params
$to = $_POST['to'] ?? $_POST['To'] ?? '';
$body = $_POST['body'] ?? $_POST['Body'] ?? '';

if ($to === '' || $body === '') { http_response_code(400); echo json_encode(['error'=>'missing to/body']); exit; }

// Env
$sid  = env('TWILIO_ACCOUNT_SID','');
$tok  = env('TWILIO_AUTH_TOKEN','');
$from = env('TWILIO_FROM_NUMBER','');
if ($sid==='' || $tok==='' || $from==='') { http_response_code(500); echo json_encode(['error'=>'twilio env missing']); exit; }

// Build request
$url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
$post = http_build_query(['From'=>$from,'To'=>$to,'Body'=>$body]);

$ch = curl_init($url);
curl_setopt_array($ch, [
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $post,
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_USERPWD => $sid.':'.$tok,
  CURLOPT_TIMEOUT => 15,
]);
$resp = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err  = curl_error($ch);
curl_close($ch);

// Audit
$ins = $pdo->prepare("INSERT INTO sms_outbound_audit(created_at,to_number,body,http_code,sid,error) VALUES(datetime('now'),?,?,?,?,?)");
$sidResp = null;
if ($code===201 && $resp) {
  $j = json_decode($resp, true);
  $sidResp = $j['sid'] ?? null;
}
$auditParams = [ $to, $body, $code, $sidResp, $err ?: (($code===201)?null:($resp ?: null)) ];

// Use the same file-lock used elsewhere to serialize long-running audit writes
with_file_lock(__DIR__ . '/../data/sqlite.write.lock', function() use ($ins, $auditParams) {
  for ($i = 0; $i < 5; $i++) {
    try { $ins->execute($auditParams); break; }
    catch (PDOException $e) {
      $m = $e->getMessage();
      if (stripos($m, 'locked') !== false || stripos($m, 'SQLITE_BUSY') !== false) { usleep(200000); continue; }
      throw $e;
    }
  }
});

if ($code===201) { echo json_encode(['ok'=>true,'sid'=>$sidResp]); }
else { http_response_code(502); echo json_encode(['error'=>'twilio_failed','code'=>$code,'detail'=>$err ?: $resp]); }
