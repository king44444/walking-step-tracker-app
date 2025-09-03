<?php
require_once __DIR__ . '/util.php';  // provides pdo()

header('Content-Type: application/json');

try {
  $db = pdo();

  // 1) For each week, sum family totals per day
  // 2) Average those weekly sums across weeks
  $sql = "
    WITH week_sums AS (
      SELECT
        week,
        SUM(COALESCE(monday,0))    AS mon,
        SUM(COALESCE(tuesday,0))   AS tue,
        SUM(COALESCE(wednesday,0)) AS wed,
        SUM(COALESCE(thursday,0))  AS thu,
        SUM(COALESCE(friday,0))    AS fri,
        SUM(COALESCE(saturday,0))  AS sat
      FROM entries
      GROUP BY week
    )
    SELECT
      ROUND(AVG(mon)) AS mon_avg,
      ROUND(AVG(tue)) AS tue_avg,
      ROUND(AVG(wed)) AS wed_avg,
      ROUND(AVG(thu)) AS thu_avg,
      ROUND(AVG(fri)) AS fri_avg,
      ROUND(AVG(sat)) AS sat_avg
    FROM week_sums
  ";

  $avg = $db->query($sql)->fetch(PDO::FETCH_ASSOC);

  echo json_encode([
    "labels" => ["Monday","Tuesday","Wednesday","Thursday","Friday","Saturday"],
    "averages" => [
      intval($avg['mon_avg'] ?? 0),
      intval($avg['tue_avg'] ?? 0),
      intval($avg['wed_avg'] ?? 0),
      intval($avg['thu_avg'] ?? 0),
      intval($avg['fri_avg'] ?? 0),
      intval($avg['sat_avg'] ?? 0),
    ]
  ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(["error" => "weekday_avg_failed", "message" => $e->getMessage()]);
}
