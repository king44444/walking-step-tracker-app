<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/lib/admin_auth.php';
require_once __DIR__ . '/lib/settings.php';

require_admin();

try {
  $resp = [
    'ai.enabled' => setting_get('ai.enabled', '1') === '1',
    'ai.nudge.enabled' => setting_get('ai.nudge.enabled', '1') === '1',
    'ai.recap.enabled' => setting_get('ai.recap.enabled', '1') === '1',
    'ai.award.enabled' => setting_get('ai.award.enabled', '1') === '1',
    // Thresholds and labels for awards
    'thresholds.cheryl' => setting_get('thresholds.cheryl', '20000'),
    'thresholds.thirty_k' => setting_get('thresholds.thirty_k', '30000'),
    'awards.first_20k' => setting_get('awards.first_20k', 'Cheryl Award'),
    'awards.first_30k' => setting_get('awards.first_30k', 'Megan Award'),
    'awards.first_15k' => setting_get('awards.first_15k', 'Dean Award'),
    // Milestone settings returned as comma-separated strings for the admin UI
    'milestones.lifetime_steps' => setting_get('milestones.lifetime_steps', '100000,250000,500000,750000,1000000'),
    'milestones.attendance_weeks' => setting_get('milestones.attendance_weeks', '25,50,100'),
  ];
  echo json_encode($resp);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'server_error']);
}
