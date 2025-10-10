<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
require_once __DIR__ . '/lib/settings.php';

// Public endpoint: return a small whitelist of settings useful for the public UI.
// Falls back to site/config.json defaults when DB value missing.
try {
  // Attempt to load from DB first
  $raw = setting_get('daily.milestones', '');

  if ($raw && is_string($raw) && strlen(trim($raw)) > 0) {
    $milestones = json_decode($raw, true);
    if (!is_array($milestones)) $milestones = [];
  } else {
    // Fallback to site/config.json
    $cfgPath = __DIR__ . '/../site/config.json';
    $milestones = [];
    if (is_readable($cfgPath)) {
      $cfg = json_decode(file_get_contents($cfgPath) ?: 'null', true);
      if (is_array($cfg)) {
        // Build defaults from known keys
        $goals = $cfg['GOALS'] ?? [];
        $thresholds = $cfg['THRESHOLDS'] ?? [];
        $labels = $cfg['CUSTOM_AWARD_LABELS'] ?? [];

        $defaults = [];

        // 1k
        $d1 = $goals['DAILY_GOAL_1K'] ?? 1000;
        $defaults[] = ['steps' => (int)$d1, 'label' => '1k'];

        // 2.5k
        $d25 = $goals['DAILY_GOAL_2_5K'] ?? 2500;
        $defaults[] = ['steps' => (int)$d25, 'label' => '2.5k'];

        // 10k
        $d10 = $goals['DAILY_GOAL_10K'] ?? 10000;
        $defaults[] = ['steps' => (int)$d10, 'label' => '10k'];

        // 15k
        $d15 = $goals['DAILY_GOAL_15K'] ?? 15000;
        $defaults[] = ['steps' => (int)$d15, 'label' => '15k'];

        // 20k (Cheryl)
        $c20 = $thresholds['CHERYL_THRESHOLD'] ?? 20000;
        $lbl20 = $labels['FIRST_20K'] ?? 'Cheryl Award';
        // Normalize label to short form if it contains number words
        $defaults[] = ['steps' => (int)$c20, 'label' => (strpos($lbl20, 'Cheryl') !== false) ? 'Cheryl' : $lbl20];

        // 30k
        $t30 = $thresholds['THIRTY_K_THRESHOLD'] ?? 30000;
        $lbl30 = $labels['FIRST_30K'] ?? 'Megan Award';
        $defaults[] = ['steps' => (int)$t30, 'label' => (strpos($lbl30, 'Megan') !== false) ? '30k' : $lbl30];

        $milestones = $defaults;
      }
    }
  }

  echo json_encode(['ok' => true, 'daily_milestones' => $milestones], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => 'server_error']);
}
