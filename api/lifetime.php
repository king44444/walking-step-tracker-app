<?php
declare(strict_types=1);

// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');
require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;
$pdo = DB::pdo();
header('Content-Type: application/json; charset=utf-8');

try {
  $sql = "
  SELECT
    u.name,
    u.sex,
    u.age,
    u.tag,
    COUNT(DISTINCT e.week) AS weeks_with_data,
    COALESCE(SUM(
      COALESCE(e.monday,0)+COALESCE(e.tuesday,0)+COALESCE(e.wednesday,0)+
      COALESCE(e.thursday,0)+COALESCE(e.friday,0)+COALESCE(e.saturday,0)
    ),0) AS total_steps,
    COALESCE(SUM(
      (e.monday IS NOT NULL)+(e.tuesday IS NOT NULL)+(e.wednesday IS NOT NULL)+
      (e.thursday IS NOT NULL)+(e.friday IS NOT NULL)+(e.saturday IS NOT NULL)
    ),0) AS total_days,
    COALESCE(MAX((
      SELECT MAX(v) FROM (
        SELECT COALESCE(e.monday,0) AS v
        UNION ALL SELECT COALESCE(e.tuesday,0)
        UNION ALL SELECT COALESCE(e.wednesday,0)
        UNION ALL SELECT COALESCE(e.thursday,0)
        UNION ALL SELECT COALESCE(e.friday,0)
        UNION ALL SELECT COALESCE(e.saturday,0)
      )
    )),0) AS lifetime_best
  FROM users u
  LEFT JOIN entries e ON e.name = u.name
  GROUP BY u.name
  ORDER BY u.name COLLATE NOCASE
  ";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as &$r) {
    $r['weeks_with_data'] = (int)($r['weeks_with_data'] ?? 0);
    $r['total_steps'] = (int)($r['total_steps'] ?? 0);
    $r['total_days'] = (int)($r['total_days'] ?? 0);
    $r['lifetime_best'] = (int)($r['lifetime_best'] ?? 0);
    $r['lifetime_avg'] = $r['total_days'] > 0 ? (int)round($r['total_steps'] / $r['total_days']) : 0;

    // Compute milestone counts per user (counts of days where steps >= milestone)
    $raw = setting_get('daily.milestones', '');
    $milestones = [];
    if (is_string($raw) && strlen(trim($raw)) > 0) {
      $decoded = json_decode($raw, true);
      if (is_array($decoded)) $milestones = $decoded;
    } else {
      // Fallback to site/config.json defaults
      $cfgPath = __DIR__ . '/../site/config.json';
      if (is_readable($cfgPath)) {
        $cfg = json_decode(file_get_contents($cfgPath) ?: 'null', true);
        if (is_array($cfg)) {
          $goals = $cfg['GOALS'] ?? [];
          $thresholds = $cfg['THRESHOLDS'] ?? [];
          $defaults = [];
          $defaults[] = ['steps' => (int)($goals['DAILY_GOAL_1K'] ?? 1000), 'label' => '1k'];
          $defaults[] = ['steps' => (int)($goals['DAILY_GOAL_2_5K'] ?? 2500), 'label' => '2.5k'];
          $defaults[] = ['steps' => (int)($goals['DAILY_GOAL_10K'] ?? 10000), 'label' => '10k'];
          $defaults[] = ['steps' => (int)($goals['DAILY_GOAL_15K'] ?? 15000), 'label' => '15k'];
          $defaults[] = ['steps' => (int)($thresholds['CHERYL_THRESHOLD'] ?? 20000), 'label' => 'Cheryl'];
          $defaults[] = ['steps' => (int)($thresholds['THIRTY_K_THRESHOLD'] ?? 30000), 'label' => '30k'];
          $milestones = $defaults;
        }
      }
    }

    $counts = [];
    if (is_array($milestones) && count($milestones) > 0) {
      foreach ($milestones as $m) {
        $k = (string)($m['steps'] ?? '');
        if ($k === '') continue;
        $counts[$k] = 0;
      }
      // fetch user's entries and count days across all weeks
      $st = $pdo->prepare('SELECT monday,tuesday,wednesday,thursday,friday,saturday FROM entries WHERE name = :name');
      $st->execute([':name' => $r['name']]);
      $entries = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($entries as $e) {
        foreach ($counts as $k => $_) {
          $steps = (int)$k;
          foreach (['monday','tuesday','wednesday','thursday','friday','saturday'] as $d) {
            $v = isset($e[$d]) && $e[$d] !== null ? (int)$e[$d] : 0;
            if ($v >= $steps) $counts[$k] += 1;
          }
        }
      }
    }
    $r['milestone_counts'] = $counts;
  }
  unset($r);

  echo json_encode(['lifetime' => $rows], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
