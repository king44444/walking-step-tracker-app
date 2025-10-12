<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

use App\Config\DB;

function read_app_version(): string {
  // Prefer site/config.json if it defines APP_VERSION
  $cfg = __DIR__ . '/../site/config.json';
  if (is_readable($cfg)) {
    $raw = @file_get_contents($cfg);
    if ($raw) {
      $json = json_decode($raw, true);
      if (is_array($json) && isset($json['APP_VERSION']) && is_string($json['APP_VERSION'])) {
        return $json['APP_VERSION'];
      }
    }
  }
  // Fallback: parse JS default from public config.js
  $js = __DIR__ . '/../public/assets/js/app/config.js';
  if (is_readable($js)) {
    $txt = @file_get_contents($js) ?: '';
    if ($txt !== '') {
      if (preg_match('~APP_VERSION\s*=\s*\"([^\"]+)\"~', $txt, $m)) {
        return (string)$m[1];
      }
    }
  }
  return 'unknown';
}

try {
  $pdo = DB::pdo();
  $dbOk = true;
  $err = null;
  $integrity = null;
  try {
    $pdo->query('SELECT 1')->fetchColumn();
  } catch (Throwable $t) {
    $dbOk = false;
    $err = $t->getMessage();
  }
  // Lightweight integrity check; tolerate failure without breaking the endpoint
  try {
    $res = $pdo->query('PRAGMA integrity_check')->fetchColumn();
    if (is_string($res)) $integrity = $res;
  } catch (Throwable $t) {
    $integrity = null;
  }

  echo json_encode([
    'ok' => true,
    'time' => date('c'),
    'version' => read_app_version(),
    'db' => [
      'ok' => $dbOk,
      'integrity' => $integrity, // typically 'ok'
      'error' => $err,
    ],
  ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => 'health_failed',
    'message' => $e->getMessage(),
  ], JSON_UNESCAPED_SLASHES);
}
