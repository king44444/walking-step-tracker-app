<?php
function verify_twilio_signature($authToken,$url,$post,$headerSig){
  if(!$authToken) return true;
  $data = $url;
  ksort($post);
  foreach($post as $k => $v){ $data .= $k . $v; }
  $sig = base64_encode(hash_hmac('sha1', $data, $authToken, true));
  return hash_equals($sig, $headerSig ?? '');
}
