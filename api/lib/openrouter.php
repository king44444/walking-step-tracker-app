<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/settings.php';

function or_chat_complete(string $model, array $messages, ?float $temp=0.5, ?int $max_tokens=160): array {
  $apiKey = openrouter_api_key();
  $body = [
    'model' => $model,
    'messages' => $messages,
    'temperature' => $temp,
    'max_tokens' => $max_tokens,
  ];
  $headers = [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
    'X-Title: King Walk Week',
  ];
  $referer = setting_get('site.url', env('SITE_URL', ''));
  if ($referer) {
    $headers[] = 'HTTP-Referer: ' . rtrim($referer, '/');
  }

  $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $res = curl_exec($ch);
  $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);
  if ($res === false || $http >= 400) {
    throw new RuntimeException('OpenRouter error: http=' . $http . ' err=' . $err);
  }
  $json = json_decode($res, true);
  if (!isset($json['choices'][0]['message']['content'])) {
    throw new RuntimeException('OpenRouter response missing content');
  }
  return $json;
}
