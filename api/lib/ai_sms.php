<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/openrouter.php';

function build_ai_sms_messages(string $userName, string $incoming, array $context=[]): array {
  $system = "You generate one short SMS reply for a family step challenge tracker. Keep under 240 characters. No links. No emojis. Be clear. If steps are included, acknowledge and confirm logging rules: before noon counts for yesterday, after noon counts for today. If message is not steps, nudge politely to send a number or 'Tue 12345'.";
  if (!empty($context['week_label'])) {
    $system .= " Current week: " . $context['week_label'] . ".";
  }
  $user = "From {$userName}: " . $incoming;
  return [
    ['role' => 'system', 'content' => $system],
    ['role' => 'user', 'content' => $user],
  ];
}

function generate_ai_sms_reply(string $userName, string $incoming, array $context=[]): array {
  $model = openrouter_model();
  $messages = build_ai_sms_messages($userName, $incoming, $context);
  $json = or_chat_complete($model, $messages, 0.4, 120);
  $text = trim($json['choices'][0]['message']['content'] ?? '');
  // Safety cleanups
  $text = preg_replace('/\s+/', ' ', $text);
  // Strip URLs
  $text = preg_replace('~https?://\S+~', '', $text);
  if (strlen($text) > 240) $text = substr($text, 0, 240);

  // Optional usage/cost extraction (best-effort)
  $cost = null;
  if (isset($json['usage'])) {
    // Some providers include token counts; cost computation depends on model pricing (omitted here)
    $cost = null;
  }

  // Log generation meta for auditing
  $dir = dirname(__DIR__, 2) . '/data/logs/ai';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  @file_put_contents($dir . '/ai_generation.log', '['.date('c')."] model=".$model." user=".$userName." msg=".str_replace(["\r","\n"], ' ', $incoming)." -> " . str_replace(["\r","\n"], ' ', $text) . "\n", FILE_APPEND);

  return ['content' => $text, 'raw' => $json, 'cost_usd' => $cost, 'model' => $model];
}

