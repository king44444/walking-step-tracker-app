<?php
declare(strict_types=1);

/**
 * Simulate an AI response (no external API calls).
 * - Logs input/output to api/logs/ai_stub.log with ISO8601 timestamp
 * - Returns a simple formatted string
 */
function simulate_ai_response(string $message, string $user_name): string {
  $out = "Simulated AI: {$user_name} sent '" . str_replace(["\r","\n"], ' ', $message) . "'";
  $line = '[' . date('c') . "] user=" . $user_name . " msg=" . str_replace(["\r","\n"], ' ', $message) . " -> " . $out . "\n";
  // Write under data/logs which is writable by www-data
  $dir = dirname(__DIR__, 2) . '/data/logs';
  if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  @file_put_contents($dir . '/ai_stub.log', $line, FILE_APPEND);
  return $out;
}
