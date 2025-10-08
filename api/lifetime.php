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
  }
  unset($r);

  echo json_encode(['lifetime' => $rows], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
