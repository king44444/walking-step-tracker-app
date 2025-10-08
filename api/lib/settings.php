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
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
  // Add updated_at column if missing
  try {
    $cols = $pdo->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($c)=>$c['name']??'', $cols);
    if (!in_array('updated_at', $names, true)) {
      $pdo->exec("ALTER TABLE settings ADD COLUMN updated_at TEXT");
    }
  } catch (Throwable $e) { /* ignore */ }
}

function settings_seed_defaults(PDO $pdo): void {
  // Defaults: all ON
  $defaults = [
    'ai.enabled' => '1',
    'ai.nudge.enabled' => '1',
    'ai.recap.enabled' => '1',
    'ai.award.enabled' => '1',
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
  // Legacy mapping for global toggle
  if ($key === 'ai.enabled') {
    // Prefer new key
    $st = $pdo->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
    $st->execute([':k' => 'ai.enabled']);
    $val = $st->fetchColumn();
    if ($val === false) {
      // Fallback to legacy key 'ai_enabled'
      $st2 = $pdo->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
      $st2->execute([':k' => 'ai_enabled']);
      $legacy = $st2->fetchColumn();
      if ($legacy !== false) { $cache[$key] = $legacy; return $legacy; }
    } else { $cache[$key] = $val; return $val; }
    // ensure defaults exist
    settings_seed_defaults($pdo);
  }
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
  if ($key === 'ai.enabled') {
    // keep legacy in sync for compatibility with old clients/scripts
    $st2 = $pdo->prepare("INSERT INTO settings(key,value,updated_at) VALUES('ai_enabled',:v,datetime('now'))
                          ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_at=datetime('now')");
    try { $st2->execute([':v'=>(string)$value]); } catch (Throwable $e) { /* ignore */ }
  }
}

