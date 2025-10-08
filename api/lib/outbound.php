<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function send_outbound_sms(string $toE164, string $body): array {
  $sid = env('TWILIO_ACCOUNT_SID','');
  $tok = env('TWILIO_AUTH_TOKEN','');
  $from = env('TWILIO_FROM','');
  if ($sid==='' || $tok==='' || $from==='') {
    throw new RuntimeException('twilio env missing');
  }
  $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
  $post = http_build_query(['From'=>$from,'To'=>$toE164,'Body'=>$body]);
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $post,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_USERPWD => $sid . ':' . $tok,
    CURLOPT_TIMEOUT => 20,
  ]);
  $resp = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);
  if ($resp === false || $code >= 400) {
    throw new RuntimeException('twilio_failed code=' . $code . ' err=' . $err . ' resp=' . (string)$resp);
  }
  $json = json_decode($resp, true);
  return ['ok'=>true, 'sid'=> $json['sid'] ?? null, 'http_code' => $code];
}

