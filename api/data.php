<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// TEMP DEBUG (remove after fix)
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
  require __DIR__ . '/db.php';

  $week = $_GET['week'] ?? null;
  if (!$week) {
    $week = $pdo->query("SELECT week FROM weeks ORDER BY week DESC LIMIT 1")->fetchColumn();
    if (!$week) { echo json_encode(['week'=>null,'rows'=>[],'finalized'=>0,'source'=>'none']); exit; }
  }

  $meta = $pdo->prepare("SELECT week, COALESCE(label, week) AS label, finalized FROM weeks WHERE week = :w");
  $meta->execute([':w' => $week]);
  $w = $meta->fetch();

  if (!$w) { echo json_encode(['week'=>$week,'rows'=>[],'finalized'=>0,'source'=>'none']); exit; }

  if ((int)$w['finalized'] === 1) {
    $snap = $pdo->prepare("SELECT json FROM snapshots WHERE week = :w");
    $snap->execute([':w' => $week]);
    $json = $snap->fetchColumn();
    $rows = $json ? json_decode($json, true) : [];
    echo json_encode(['week'=>$w['week'],'label'=>$w['label'],'finalized'=>1,'source'=>'snapshot','rows'=>is_array($rows)?$rows:[]], JSON_UNESCAPED_SLASHES);
    exit;
  }

  // Select rows and include per-day reported_at timestamps so frontend can reason about first-reports and thresholds.
  $q = $pdo->prepare("
    SELECT
      name,
      monday,
      tuesday,
      wednesday,
      thursday,
      friday,
      saturday,
      sex,
      age,
      tag,
      mon_reported_at,
      tue_reported_at,
      wed_reported_at,
      thu_reported_at,
      fri_reported_at,
      sat_reported_at
    FROM entries
    WHERE week = :w
    ORDER BY LOWER(name)
  ");
  $q->execute([':w' => $week]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  // Map server weekday (0=Sun..6=Sat) to DAY_ORDER index (0=Mon..5=Sat), use -1 for Sunday
  $wday = (int)date('w'); // 0=Sun..6=Sat
  $todayIdx = ($wday === 0) ? -1 : $wday - 1;

  // Build per-day firstReports array (Mon..Sat => idx 0..5)
  $dayCols = [
    ['day'=>'monday','rep'=>'mon_reported_at'],
    ['day'=>'tuesday','rep'=>'tue_reported_at'],
    ['day'=>'wednesday','rep'=>'wed_reported_at'],
    ['day'=>'thursday','rep'=>'thu_reported_at'],
    ['day'=>'friday','rep'=>'fri_reported_at'],
    ['day'=>'saturday','rep'=>'sat_reported_at']
  ];
  $firstReports = [];
  foreach ($dayCols as $idx => $d) {
    $dayCol = $d['day'];
    $repCol = $d['rep'];
    $sql = "SELECT name, $dayCol AS value, $repCol AS reported_at FROM entries WHERE week = :w AND $repCol IS NOT NULL ORDER BY $repCol ASC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':w' => $week]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
      $firstReports[$idx] = ['dayIdx' => $idx, 'name' => $r['name'], 'value' => (int)$r['value'], 'reported_at' => (int)$r['reported_at']];
    } else {
      $firstReports[$idx] = null;
    }
  }

  // Compute lifetimeStart: total steps strictly before this week for each person
  $stLifetime = $pdo->prepare("
    SELECT name,
      COALESCE(SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)),0) AS total_before
    FROM entries
    WHERE week < :w
    GROUP BY name
  ");
  $stLifetime->execute([':w' => $week]);
  $lifetimeStart = [];
  foreach ($stLifetime->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $lifetimeStart[$r['name']] = (int)$r['total_before'];
  }

  echo json_encode([
    'week'=>$w['week'],
    'label'=>$w['label'],
    'finalized'=>0,
    'source'=>'live',
    'todayIdx'=>$todayIdx,
    'rows'=>$rows,
    'firstReports'=>$firstReports,
    'lifetimeStart'=>$lifetimeStart
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
}
