<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;

function api_log(string $msg): void {
  $dir = __DIR__ . '/logs';
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  @file_put_contents($dir . '/app.log', '['.date('c')."] weeks.php " . $msg . "\n", FILE_APPEND);
}

function iso_date(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  if (!preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $s, $m)) return null;
  $y=(int)$m[1]; $mo=(int)$m[2]; $d=(int)$m[3];
  if (!checkdate($mo, $d, $y)) return null;
  return sprintf('%04d-%02d-%02d', $y, $mo, $d);
}

function columns(PDO $pdo, string $table): array {
  $cols = $pdo->query("PRAGMA table_info(".$table.")")->fetchAll(PDO::FETCH_ASSOC);
  return array_map(fn($c)=>$c['name'] ?? '', $cols);
}

try {
  $pdo = DB::pdo();
  // Ensure schema exists
  ob_start();
  require_once __DIR__ . '/migrate.php';
  ob_end_clean();

  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

  if ($method === 'GET') {
    // List weeks sorted desc and normalize/dedupe output
    $cols = columns($pdo, 'weeks');
    $hasStarts = in_array('starts_on', $cols, true);
    $sql = $hasStarts
      ? "SELECT COALESCE(starts_on, week) AS starts_on, COALESCE(label, COALESCE(starts_on, week)) AS label, COALESCE(finalized, CASE WHEN finalized_at IS NOT NULL THEN 1 ELSE 0 END, 0) AS finalized FROM weeks WHERE COALESCE(starts_on, week) IS NOT NULL ORDER BY COALESCE(starts_on, week) DESC"
      : "SELECT week AS starts_on, COALESCE(label, week) AS label, COALESCE(finalized, CASE WHEN finalized_at IS NOT NULL THEN 1 ELSE 0 END, 0) AS finalized FROM weeks WHERE week IS NOT NULL ORDER BY week DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
      $iso = iso_date((string)($r['starts_on'] ?? '')) ?? (string)($r['starts_on'] ?? '');
      if (!isset($map[$iso])) {
        $map[$iso] = [
          'starts_on' => $iso,
          'label' => (string)($r['label'] ?? $iso),
          'finalized' => (int)($r['finalized'] ?? 0)
        ];
      } else {
        // merge: prefer finalized=1 and keep a non-empty label
        if (($r['finalized'] ?? 0) && !($map[$iso]['finalized'] ?? 0)) $map[$iso]['finalized'] = 1;
        $lbl = trim((string)($r['label'] ?? ''));
        if ($lbl !== '' && ($map[$iso]['label'] ?? '') === '') $map[$iso]['label'] = $lbl;
      }
    }
    // sort desc by starts_on
    $out = array_values($map);
    usort($out, function($a,$b){ return strcmp($b['starts_on'], $a['starts_on']); });
    echo json_encode(['ok'=>true, 'weeks'=>$out], JSON_UNESCAPED_SLASHES);
    return;
  }

  if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
      $raw = (string)($_POST['date'] ?? '');
      $iso = iso_date($raw);
      if (!$iso) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'bad_date']);
        return;
      }
      $cols = columns($pdo, 'weeks');
      $hasStarts = in_array('starts_on', $cols, true);
      $hasWeek = in_array('week', $cols, true);
      // Upsert semantics
      $pdo->beginTransaction();
      // Exists?
      $exists = false;
      if ($hasStarts) {
        $st = $pdo->prepare('SELECT 1 FROM weeks WHERE starts_on = :d LIMIT 1');
        $st->execute([':d'=>$iso]);
        $exists = (bool)$st->fetchColumn();
      }
      if (!$exists && $hasWeek) {
        $st = $pdo->prepare('SELECT 1 FROM weeks WHERE week = :d LIMIT 1');
        $st->execute([':d'=>$iso]);
        $exists = (bool)$st->fetchColumn();
      }
      if ($exists) {
        $pdo->commit();
        echo json_encode(['ok'=>true, 'created'=>false, 'starts_on'=>$iso]);
        return;
      }
      // Insert
      if ($hasStarts && $hasWeek) {
        $ins = $pdo->prepare('INSERT INTO weeks(starts_on, week, label, finalized) VALUES(:d, :d, :l, 0)');
        $ins->execute([':d'=>$iso, ':l'=>$iso]);
      } elseif ($hasStarts) {
        $ins = $pdo->prepare('INSERT INTO weeks(starts_on, label, finalized) VALUES(:d, :l, 0)');
        $ins->execute([':d'=>$iso, ':l'=>$iso]);
      } else { // fallback legacy
        $ins = $pdo->prepare('INSERT INTO weeks(week, label, finalized) VALUES(:d, :l, 0)');
        $ins->execute([':d'=>$iso, ':l'=>$iso]);
      }
      $pdo->commit();
      echo json_encode(['ok'=>true, 'created'=>true, 'starts_on'=>$iso]);
      return;
    }

    if ($action === 'delete') {
      $raw = (string)($_POST['date'] ?? '');
      $iso = iso_date($raw);
      if (!$iso) {
        http_response_code(400);
        echo json_encode(['ok'=>false,'error'=>'bad_date']);
        return;
      }
      $force = !!(($_POST['force'] ?? '0') === '1' || ($_POST['force'] ?? '') === 'true');
      $cols = columns($pdo, 'weeks'); $hasStarts = in_array('starts_on', $cols, true); $hasWeek = in_array('week', $cols, true);
      $pdo->beginTransaction();
      // Resolve the textual key used in entries table (legacy uses entries.week)
      $wk = $iso;
      if ($hasWeek) {
        $st = $pdo->prepare('SELECT COALESCE(week, starts_on) FROM weeks WHERE starts_on = :d OR week = :d LIMIT 1');
        $st->execute([':d'=>$iso]);
        $wk = (string)($st->fetchColumn() ?: $iso);
      }
      // Count entries
      $cnt = 0;
      try {
        $stc = $pdo->prepare('SELECT COUNT(1) FROM entries WHERE week = :w');
        $stc->execute([':w'=>$wk]);
        $cnt = (int)$stc->fetchColumn();
      } catch (Throwable $e) {
        // entries table may not exist; treat as 0
        $cnt = 0;
      }
      if ($cnt > 0 && !$force) {
        $pdo->rollBack();
        echo json_encode(['ok'=>false,'error'=>'week_has_entries','count'=>$cnt]);
        return;
      }
      if ($cnt > 0 && $force) {
        $delE = $pdo->prepare('DELETE FROM entries WHERE week = :w');
        $delE->execute([':w'=>$wk]);
      }
      // Delete week row
      if ($hasStarts) {
        $delW = $pdo->prepare('DELETE FROM weeks WHERE starts_on = :d OR week = :d');
        $delW->execute([':d'=>$iso]);
      } else {
        $delW = $pdo->prepare('DELETE FROM weeks WHERE week = :d');
        $delW->execute([':d'=>$iso]);
      }
      $pdo->commit();
      echo json_encode(['ok'=>true, 'deleted'=>true, 'starts_on'=>$iso]);
      return;
    }

    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'bad_request']);
    return;
  }

  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'bad_method']);
} catch (Throwable $e) {
  api_log($e->getMessage());
  // Never expose internal errors; keep UI alive.
  echo json_encode(['ok'=>false,'error'=>'server_error']);
}
