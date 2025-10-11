<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Lightweight settings helper with per-request cache and legacy key mapping.
 * Uses the `settings` table with schema: (key TEXT PRIMARY KEY, value TEXT, updated_at TEXT)
 */

function settings_pdo(): PDO {
  return cfg_pdo();
}

function settings_ensure_schema(PDO $pdo): void {
  static $done = false; if ($done) return; $done = true;
  // Schema now created by migration - just ensure it exists
  try {
    $pdo->query("SELECT 1 FROM settings LIMIT 1");
  } catch (Throwable $e) {
    throw new RuntimeException("Settings table not found - run migrations first");
  }
}

function settings_seed_defaults(PDO $pdo): void {
  // Defaults: all ON
  $defaults = [
    'ai.enabled' => '1',
    'ai.nudge.enabled' => '1',
    'ai.recap.enabled' => '1',
    'ai.award.enabled' => '1',
    'ai.image.provider' => 'openrouter',
    'sms.inbound_rate_window_sec' => '60',
    'sms.ai_rate_window_sec' => '120',
    'sms.audit_retention_days' => '90',
    'reminders.default_morning' => '07:30',
    'reminders.default_evening' => '20:00',
    'sms.admin_prefix_enabled' => '0',
    'sms.admin_password' => '',
    'sms.undo_enabled' => '0',
    'app.public_base_url' => '',
  ];
  foreach ($defaults as $k => $v) {
    $st = $pdo->prepare("INSERT OR IGNORE INTO settings(key,value,updated_at) VALUES(:k,:v,datetime('now'))");
    try { $st->execute([':k'=>$k, ':v'=>$v]); } catch (Throwable $e) { /* ignore */ }
  }
}

function setting_get(string $key, $default=null) {
  static $cache = null; if ($cache === null) $cache = [];
  if (array_key_exists($key, $cache)) return $cache[$key];
  $pdo = settings_pdo();
  settings_ensure_schema($pdo);
  $st = $pdo->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
  $st->execute([':k' => $key]);
  $val = $st->fetchColumn();
  if ($val === false) { $cache[$key] = $default; return $default; }
  $cache[$key] = $val;
  return $val;
}

function setting_set(string $key, $value): void {
  static $cache = null; if ($cache === null) $cache = [];
  $pdo = settings_pdo();
  settings_ensure_schema($pdo);
  $st = $pdo->prepare("INSERT INTO settings(key,value,updated_at) VALUES(:k,:v,datetime('now'))
                       ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime('now')");
  $st->execute([':k'=>$key, ':v'=>(string)$value]);
  $cache[$key] = (string)$value;
  // No legacy key sync; new keys are canonical.
}
