<?php
declare(strict_types=1);

/**
 * Helper functions for global settings
 * - Uses existing PDO connection (pdo() from api/util.php if available),
 *   falling back to App\Config\DB::pdo() when pdo() is not present.
 */

if (!function_exists('cfg_pdo')) {
  function cfg_pdo(): PDO {
    if (function_exists('pdo')) {
      return pdo();
    }
    // Fallback to autoloaded DB if util.php not included
    require_once __DIR__ . '/../../vendor/autoload.php';
    \App\Core\Env::bootstrap(dirname(__DIR__, 2));
    return \App\Config\DB::pdo();
  }
}

// Lightweight env reader that checks $_ENV, getenv, then .env files if present.
if (!function_exists('env')) {
  function env(string $k, $default=null) {
    if (isset($_ENV[$k])) return $_ENV[$k];
    $g = getenv($k);
    if ($g !== false) return $g;
    // Fallback: attempt to read from project .env files (best-effort, no parse library)
    $root = dirname(__DIR__, 2);
    foreach (['.env.local', '.env'] as $file) {
      $path = $root . '/' . $file;
      if (is_file($path)) {
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
          if (strpos($line, '=') === false) continue;
          [$key, $val] = explode('=', $line, 2);
          if ($key === $k) return $val;
        }
      }
    }
    return $default;
  }
}

if (!function_exists('openrouter_api_key')) {
  function openrouter_api_key(): string {
    $key = env('OPENROUTER_API_KEY', '');
    if ($key === '') throw new RuntimeException('OpenRouter API key missing');
    return $key;
  }
}

if (!function_exists('openrouter_model')) {
  function openrouter_model(): string {
    // DB setting takes precedence if present
    $dbVal = function_exists('get_setting') ? get_setting('openrouter_model') : null;
    if ($dbVal && $dbVal !== '') return $dbVal;
    return (string)env('OPENROUTER_MODEL', 'anthropic/claude-3.5-sonnet');
  }
}

if (!function_exists('get_setting')) {
  function get_setting(string $key): ?string {
    try {
      $pdo = cfg_pdo();
      $st = $pdo->prepare('SELECT value FROM settings WHERE key = :k LIMIT 1');
      $st->execute([':k' => $key]);
      $val = $st->fetchColumn();
      return ($val === false) ? null : (string)$val;
    } catch (Throwable $e) {
      error_log('get_setting failed: ' . $e->getMessage());
      return null;
    }
  }
}

if (!function_exists('set_setting')) {
  function set_setting(string $key, string $value): void {
    try {
      $pdo = cfg_pdo();
      $st = $pdo->prepare("INSERT INTO settings(key,value) VALUES(:k,:v)
                           ON CONFLICT(key) DO UPDATE SET value=excluded.value");
      $st->execute([':k' => $key, ':v' => $value]);
    } catch (Throwable $e) {
      error_log('set_setting failed: ' . $e->getMessage());
    }
  }
}
