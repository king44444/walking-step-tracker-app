<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;

function api_log_data(string $msg): void {
  $dir = __DIR__ . '/../data/logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($dir . '/app.log', '['.date('c')."] data.php " . $msg . "\n", FILE_APPEND);
}

function iso_date_norm(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  if (!preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $s, $m)) return null;
  $y=(int)$m[1]; $mo=(int)$m[2]; $d=(int)$m[3];
  if (!checkdate($mo, $d, $y)) return null;
  return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

try {
  $pdo = DB::pdo();
  // Ensure schema exists; silence output
  ob_start();
  require_once __DIR__ . '/migrate.php';
  ob_end_clean();

  $req = (string)($_GET['week'] ?? '');
  $iso = iso_date_norm($req) ?? $req;

  // If no date provided, select latest canonical
  if ($iso === '') {
    // Try starts_on first, then legacy week
    $wk = $pdo->query("SELECT COALESCE(starts_on, week) FROM weeks ORDER BY COALESCE(starts_on, week) DESC LIMIT 1")->fetchColumn();
    if (!$wk) { echo json_encode(['ok'=>true,'week'=>null,'rows'=>[],'finalized'=>0,'source'=>'none']); return; }
    $iso = iso_date_norm((string)$wk) ?? (string)$wk;
  }

  // Generate variants to resolve duplicates (e.g., 2025-10-5 vs 2025-10-05)
  $alts = [];
  if ($iso_date = iso_date_norm($iso)) {
    [$y,$m,$d] = explode('-', $iso_date);
    $alts = array_values(array_unique([
      $iso_date,
      sprintf('%d-%d-%d', (int)$y, (int)$m, (int)$d),
      sprintf('%04d-%d-%02d', (int)$y, (int)$m, (int)$d),
      sprintf('%04d-%02d-%d', (int)$y, (int)$m, (int)$d),
    ]));
  } else {
    $alts = [$iso];
  }

  // Resolve meta row and the key used for entries/snapshots (legacy uses entries.week)
  $wkKey = null; $label = null; $finalized = 0;
  $metaRow = null;
  foreach ($alts as $cand) {
    $st = $pdo->prepare("SELECT week, starts_on, COALESCE(label, COALESCE(starts_on, week)) AS label, COALESCE(finalized, CASE WHEN finalized_at IS NOT NULL THEN 1 ELSE 0 END, 0) AS finalized FROM weeks WHERE starts_on = :d OR week = :d LIMIT 1");
    $st->execute([':d'=>$cand]);
    $metaRow = $st->fetch(PDO::FETCH_ASSOC);
    if ($metaRow) { break; }
  }
  if ($metaRow) {
    $wkKey = (string)($metaRow['week'] ?? '') ?: (string)($metaRow['starts_on'] ?? '');
    $label = (string)($metaRow['label'] ?? ($wkKey ?: $iso));
    $finalized = (int)($metaRow['finalized'] ?? 0);
  } else {
    // No week row; fall back to iso
    $wkKey = $alts[0] ?? $iso;
    $label = $wkKey;
    $finalized = 0;
  }

  if ($finalized === 1) {
    $snap = $pdo->prepare("SELECT json FROM snapshots WHERE week = :w");
    $snap->execute([':w' => $wkKey]);
    $json = $snap->fetchColumn();
    $rows = $json ? json_decode((string)$json, true) : [];
    echo json_encode(['ok'=>true,'week'=>$wkKey,'label'=>$label,'finalized'=>1,'source'=>'snapshot','rows'=>is_array($rows)?$rows:[]], JSON_UNESCAPED_SLASHES);
    return;
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
  // Try entries lookup across variants until data is found; default to wkKey
  $used = null; $rows = [];
  foreach (array_values(array_unique(array_merge([$wkKey], $alts))) as $cand) {
    $q->execute([':w' => $cand]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    if ($rows && count($rows)) { $used = $cand; break; }
  }
  if ($used === null) {
    $used = $wkKey;
    $q->execute([':w' => $used]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
  }

  // Attach AI awards for each row (if a matching user exists).
  // Awards returned as array of { kind, milestone_value, image_path, created_at }.
  try {
    // Build unique list of names present in rows.
    $names = array_values(array_unique(array_filter(array_map(function($r){ return $r['name'] ?? null; }, $rows), function($n){ return $n !== null && $n !== ''; })));
    if (count($names) > 0) {
      // Query users to map names -> user_id
      $placeholders = implode(',', array_fill(0, count($names), '?'));
      $st = $pdo->prepare("SELECT id, name FROM users WHERE name IN ($placeholders)");
      $st->execute($names);
      $userMap = [];
      $userIds = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $userMap[$u['name']] = (int)$u['id'];
        $userIds[] = (int)$u['id'];
      }

      // Fetch awards for these user IDs
      $awardsMap = [];
      if (count($userIds) > 0) {
        $ph2 = implode(',', array_fill(0, count($userIds), '?'));
        $st2 = $pdo->prepare("SELECT user_id, kind, milestone_value, image_path, created_at FROM ai_awards WHERE user_id IN ($ph2) ORDER BY created_at ASC");
        $st2->execute($userIds);
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $a) {
          $uid = (int)$a['user_id'];
          if (!isset($awardsMap[$uid])) $awardsMap[$uid] = [];
          $awardsMap[$uid][] = [
            'kind' => $a['kind'],
            'milestone_value' => (int)$a['milestone_value'],
            'image_path' => $a['image_path'],
            'created_at' => $a['created_at']
          ];
        }
      }

      // Attach awards and user_id to each row by name -> user_id -> awards
      foreach ($rows as &$r) {
        $r['awards'] = [];
        $n = $r['name'] ?? null;
        if ($n !== null && isset($userMap[$n])) {
          $uid = $userMap[$n];
          $r['user_id'] = $uid;
          $r['awards'] = $awardsMap[$uid] ?? [];
        }
      }
      unset($r);
    } else {
      // No rows/names; ensure awards key exists for consistency
      foreach ($rows as &$r) { $r['awards'] = []; } unset($r);
    }
  } catch (Throwable $e) {
    // Non-fatal: log and continue without awards
    error_log("data.php: failed to load ai_awards: " . $e->getMessage());
    foreach ($rows as &$r) { $r['awards'] = []; } unset($r);
  }

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
    $st->execute([':w' => $used]);
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
  $stLifetime->execute([':w' => $used]);
  $lifetimeStart = [];
  foreach ($stLifetime->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $lifetimeStart[$r['name']] = (int)$r['total_before'];
  }

  echo json_encode([
    'ok'=>true,
    'week'=>$used,
    'label'=>$label,
    'finalized'=>0,
    'source'=>'live',
    'todayIdx'=>$todayIdx,
    'rows'=>$rows,
    'firstReports'=>$firstReports,
    'lifetimeStart'=>$lifetimeStart
  ], JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
  api_log_data($e->getMessage());
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
