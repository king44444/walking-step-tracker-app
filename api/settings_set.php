<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/../app/Security/Csrf.php';

require_admin();

function json_input_assoc(): array {
  $raw = file_get_contents('php://input') ?: '';
  $j = json_decode($raw, true);
  return is_array($j) ? $j : [];
}

try {
  $in = json_input_assoc();
  if (empty($in)) { $in = $_POST; }
  $csrf = $_SERVER['HTTP_X_CSRF'] ?? ($in['csrf'] ?? '');
  if (!\App\Security\Csrf::validate((string)$csrf)) { http_response_code(403); echo json_encode(['error'=>'invalid_csrf']); exit; }

  $key = isset($in['key']) ? (string)$in['key'] : '';
  if ($key === '') { http_response_code(400); echo json_encode(['error'=>'missing_key']); exit; }
  // Allow milestone settings so admin UI can save comma-separated milestone lists
  $allowed = [
    'ai.enabled',
    'ai.nudge.enabled',
    'ai.recap.enabled',
    'ai.award.enabled',
    // Public UI toggles
    'ui.nudges.enabled',
    // Award thresholds and labels editable via admin UI
    'thresholds.cheryl',
    'thresholds.thirty_k',
    'awards.first_20k',
    'awards.first_30k',
    'awards.first_15k',
    // Daily milestones (JSON string) editable via admin UI
    'daily.milestones',
    // Milestone settings returned as comma-separated strings for the admin UI
    'milestones.lifetime_steps',
    'milestones.attendance_days',
    // SMS + reminder configuration (admin UI)
    'sms.admin_prefix_enabled',
    'sms.admin_password',
    'app.public_base_url',
    'sms.inbound_rate_window_sec',
    'sms.ai_rate_window_sec',
    'sms.backfill_days',
    'reminders.default_morning',
    'reminders.default_evening',
    // AI image prompt configuration
    'ai.image.prompts.lifetime',
  ];
  if (!in_array($key, $allowed, true)) { http_response_code(400); echo json_encode(['error'=>'bad_key']); exit; }
  $val = $in['value'] ?? null;
  if (is_bool($val)) { $val = $val ? '1' : '0'; }
  if (!is_string($val)) { $val = (string)$val; }
  // Accept arbitrary string values for milestone lists (comma-separated ints)
  setting_set($key, $val);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error']);
}
