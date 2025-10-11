<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/lib/admin_auth.php';
require_admin();

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;

require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/ai_images.php';

function j200($arr){ echo json_encode($arr, JSON_UNESCAPED_SLASHES); exit; }

try {
  $pdo = DB::pdo();

  // Test 1: Database connection
  $db_ok = true;
  $db_error = null;
  try {
    $pdo->query("SELECT 1");
  } catch (Throwable $e) {
    $db_ok = false;
    $db_error = $e->getMessage();
  }

  // Test 2: Settings table
  $settings_ok = true;
  $settings_error = null;
  $settings_count = 0;
  try {
    settings_ensure_schema($pdo);
    $st = $pdo->query("SELECT COUNT(*) FROM settings");
    $settings_count = (int)$st->fetchColumn();
  } catch (Throwable $e) {
    $settings_ok = false;
    $settings_error = $e->getMessage();
  }

  // Test 3: AI settings
  $ai_settings = [
    'ai.enabled' => setting_get('ai.enabled', '1'),
    'ai.award.enabled' => setting_get('ai.award.enabled', '1'),
    'ai.image.provider' => setting_get('ai.image.provider', 'local'),
    'ai.image.model' => setting_get('ai.image.model', ''),
  ];

  // Test 4: Environment variables
  $env_vars = [
    'OPENROUTER_API_KEY' => !empty(env('OPENROUTER_API_KEY', '')) ? 'SET' : 'NOT_SET',
    'OPENROUTER_MODEL' => env('OPENROUTER_MODEL', 'NOT_SET'),
  ];

  // Test 5: AI image generation capability
  $ai_capable = ai_image_can_generate();
  $has_provider = ai_image_has_provider();

  // Test 6: Directory permissions
  $test_dir = dirname(__DIR__) . '/site/assets/awards/1';
  $dir_writable = is_writable(dirname($test_dir)) && (!file_exists($test_dir) || is_writable($test_dir));

  // Test 7: GD extension
  $gd_available = function_exists('imagecreatetruecolor') && function_exists('imagewebp');

  j200([
    'timestamp' => date('c'),
    'database' => [
      'ok' => $db_ok,
      'error' => $db_error,
    ],
    'settings_table' => [
      'ok' => $settings_ok,
      'error' => $settings_error,
      'count' => $settings_count,
    ],
    'ai_settings' => $ai_settings,
    'environment' => $env_vars,
    'capabilities' => [
      'ai_image_can_generate' => $ai_capable,
      'ai_image_has_provider' => $has_provider,
      'directory_writable' => $dir_writable,
      'gd_available' => $gd_available,
    ],
    'recommendations' => [
      'run_migrations' => !$settings_ok,
      'configure_ai_settings' => empty($ai_settings['ai.image.model']),
      'set_openrouter_key' => $env_vars['OPENROUTER_API_KEY'] === 'NOT_SET',
      'check_permissions' => !$dir_writable,
    ]
  ]);

} catch (Throwable $e) {
  j200([
    'error' => 'debug_endpoint_failed',
    'message' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
  ]);
}
