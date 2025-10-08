<?php
declare(strict_types=1);

// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');
header('Content-Type: application/json; charset=utf-8');

try {
  require_once __DIR__ . '/../vendor/autoload.php';
  App\Core\Env::bootstrap(dirname(__DIR__));
  use App\Config\DB;
  $pdo = DB::pdo();
  ob_start(); require_once __DIR__ . '/migrate.php'; ob_end_clean();
  require_once __DIR__ . '/lib/admin_auth.php';
  require_once __DIR__ . '/lib/settings.php';
  require_admin();
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  require_once __DIR__ . '/../app/Security/Csrf.php';
  $csrf = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? '');
  if (!\App\Security\Csrf::validate((string)$csrf)) {
    http_response_code(403);
    echo json_encode(['error' => 'invalid_csrf']);
    exit;
  }

  if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
  }

  // Global/category flags
  $globalOn = setting_get('ai.enabled', '1') === '1';
  $nudgeOn  = setting_get('ai.nudge.enabled', '1') === '1';
  $recapOn  = setting_get('ai.recap.enabled', '1') === '1';
  if (!$globalOn) { error_log('[ai] skipped category=all reason=ai.disabled'); echo json_encode(['ok'=>true,'skipped'=>true,'reason'=>'ai.disabled']); exit; }

  $week = trim((string)($_POST['week'] ?? ''));
  if ($week === '') {
    // pick latest week
    $week = (string)($pdo->query("SELECT week FROM weeks ORDER BY week DESC LIMIT 1")->fetchColumn() ?? '');
  }
  if ($week === '') {
    http_response_code(400);
    echo json_encode(['error' => 'No week available']);
    exit;
  }

  // Load users
  $usersRaw = $pdo->query("SELECT id, name, rival_id FROM users")->fetchAll(PDO::FETCH_ASSOC);
  $users = [];
  foreach ($usersRaw as $u) {
    $users[(int)$u['id']] = ['id'=>(int)$u['id'], 'name'=>$u['name'], 'rival_id'=> ($u['rival_id']===null?null:(int)$u['rival_id'])];
  }

  // Helper to compute a week's total for a given user name
  $getWeekTotalStmt = $pdo->prepare("SELECT COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0) AS total,
    (CASE WHEN monday IS NULL OR monday = 0 THEN 1 ELSE 0 END)
    + (CASE WHEN tuesday IS NULL OR tuesday = 0 THEN 1 ELSE 0 END)
    + (CASE WHEN wednesday IS NULL OR wednesday = 0 THEN 1 ELSE 0 END)
    + (CASE WHEN thursday IS NULL OR thursday = 0 THEN 1 ELSE 0 END)
    + (CASE WHEN friday IS NULL OR friday = 0 THEN 1 ELSE 0 END)
    + (CASE WHEN saturday IS NULL OR saturday = 0 THEN 1 ELSE 0 END) AS missing_days
    FROM entries WHERE week = :week AND name = :name LIMIT 1");

  // Load totals for target week (map by user_id)
  $totals = [];
  foreach ($users as $uid => $u) {
    $getWeekTotalStmt->execute([':week'=>$week, ':name'=>$u['name']]);
    $row = $getWeekTotalStmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
      $totals[$uid] = ['total' => (int)$row['total'], 'missing_days' => (int)$row['missing_days']];
    } else {
      // no entry -> total 0, missing_days = 6
      $totals[$uid] = ['total' => 0, 'missing_days' => 6];
    }
  }

  // Compute historical best per user (max single-week total)
  $bestStmt = $pdo->prepare("SELECT MAX(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)) AS best
    FROM entries WHERE name = :name");
  $bests = [];
  foreach ($users as $uid => $u) {
    $bestStmt->execute([':name'=>$u['name']]);
    $bests[$uid] = (int)($bestStmt->fetchColumn() ?? 0);
  }

  // Determine prior week (if any)
  $priorWeek = (string)($pdo->prepare("SELECT week FROM weeks WHERE week < :week ORDER BY week DESC LIMIT 1")->execute([':week'=>$week]) ?: '');
  $priorWeekRow = $pdo->prepare("SELECT week FROM weeks WHERE week < :week ORDER BY week DESC LIMIT 1");
  $priorWeekRow->execute([':week'=>$week]);
  $priorWeek = (string)($priorWeekRow->fetchColumn() ?? '');

  $priorTotals = [];
  if ($priorWeek !== '') {
    foreach ($users as $uid => $u) {
      $getWeekTotalStmt->execute([':week'=>$priorWeek, ':name'=>$u['name']]);
      $row = $getWeekTotalStmt->fetch(PDO::FETCH_ASSOC);
      $priorTotals[$uid] = $row ? (int)$row['total'] : 0;
    }
  }

  // Build ranking for top3 for this week
  $rank = [];
  foreach ($users as $uid => $u) {
    $rank[] = ['id'=>$uid, 'name'=>$u['name'], 'total'=>$totals[$uid]['total']];
  }
  usort($rank, function($a,$b){ return $b['total'] <=> $a['total'] ?: strcmp($a['name'],$b['name']); });
  $top3 = array_slice($rank, 0, 3);

  // Compute most improved
  $mostImproved = null; // ['id'=>, 'name'=>, 'delta'=>]
  if ($priorWeek !== '') {
    foreach ($users as $uid => $u) {
      $delta = $totals[$uid]['total'] - ($priorTotals[$uid] ?? 0);
      if ($mostImproved === null || $delta > $mostImproved['delta']) {
        $mostImproved = ['id'=>$uid, 'name'=>$u['name'], 'delta'=>$delta];
      }
    }
    if ($mostImproved !== null && $mostImproved['delta'] <= 0) $mostImproved = null;
  }

  // Build missing list
  $missingList = [];
  foreach ($users as $uid => $u) {
    $md = $totals[$uid]['missing_days'];
    if ($md > 0) $missingList[] = ['name'=>$u['name'], 'missing'=>$md];
  }

  // Load ai_messages rows to update
  $q = $pdo->prepare("SELECT id, type, user_id FROM ai_messages WHERE model = 'rules-v0' AND (content = '' OR content IS NULL) AND week = :week");
  $q->execute([':week'=>$week]);
  $rows = $q->fetchAll(PDO::FETCH_ASSOC);

  $updateStmt = $pdo->prepare("UPDATE ai_messages SET content = :content WHERE id = :id");

  $updated = 0; $skipped = 0;
  foreach ($rows as $r) {
    $id = (int)$r['id'];
    $type = (string)$r['type'];
    $user_id = $r['user_id'] !== null ? (int)$r['user_id'] : null;
    $content = '';

    if ($type === 'nudge' && !$nudgeOn) { $skipped++; error_log('[ai] skipped category=nudge reason=nudge.disabled id='.$id.' week='.$week); continue; }
    if ($type === 'recap' && !$recapOn) { $skipped++; error_log('[ai] skipped category=recap reason=recap.disabled id='.$id.' week='.$week); continue; }

    if ($type === 'nudge' && $user_id !== null && isset($users[$user_id])) {
      $name = $users[$user_id]['name'] ?? 'You';
      $userTotal = $totals[$user_id]['total'] ?? 0;
      $userBest = $bests[$user_id] ?? 0;
      $deltaBest = $userBest - $userTotal;
      if ($deltaBest < 0) $deltaBest = 0;
      $deltaBestText = number_format($deltaBest, 0, '.', ',');

      // Rival sentence
      $rivalSentence = '';
      $gap = null;
      $rivalId = $users[$user_id]['rival_id'] ?? null;
      if ($rivalId !== null && isset($users[$rivalId])) {
        $rivalTotal = $totals[$rivalId]['total'] ?? 0;
        $gap = $rivalTotal - $userTotal;
        if ($gap < 0) $gap = 0;
        if ($gap > 0) {
          $rivalName = $users[$rivalId]['name'] ?? '';
          $rivalSentence = "{$rivalName} is {$gap} ahead.";
        }
      } else {
        // find nearest higher total
        $found = null;
        foreach ($rank as $candidate) {
          if ($candidate['id'] === $user_id) continue;
          if ($candidate['total'] > $userTotal) { $found = $candidate; break; }
        }
        if ($found) {
          $gap = $found['total'] - $userTotal;
          if ($gap < 0) $gap = 0;
          if ($gap > 0) $rivalSentence = "{$found['name']} is {$gap} ahead.";
        }
      }

      $parts = [];
      $parts[] = "{$name}, you're {$deltaBestText} from your weekly best.";
      if ($rivalSentence !== '') $parts[] = $rivalSentence;
      $parts[] = "One solid day puts you back in it.";
      $content = trim(preg_replace('/\s+/', ' ', implode(' ', $parts)));

      // Clamp length to 160 (use mb_* when available, fall back to byte-safe functions)
      if (function_exists('mb_strlen')) {
        if (mb_strlen($content) > 160) {
          $content = mb_substr($content, 0, 157) . '...';
        }
      } else {
        if (strlen($content) > 160) {
          $content = substr($content, 0, 157) . '...';
        }
      }

    } elseif ($type === 'recap') {
      $pieces = [];
      // Top 3
      $tops = [];
      foreach ($top3 as $i => $t) {
        $rankNo = $i + 1;
        $tops[] = "{$rankNo} {$t['name']} {$t['total']}";
      }
      $pieces[] = 'Top: ' . implode(', ', $tops) . '.';

      // Most improved
      if ($mostImproved !== null) {
        $pieces[] = "Most improved: {$mostImproved['name']}+{$mostImproved['delta']}.";
      }

      // Missing
      if (count($missingList) > 0) {
        $missStr = [];
        foreach ($missingList as $m) {
          $missStr[] = "{$m['name']}({$m['missing']})";
        }
        $pieces[] = 'Missing: ' . implode(', ', $missStr) . '.';
      }

      $content = trim(preg_replace('/\s+/', ' ', implode(' ', $pieces)));
      if (function_exists('mb_strlen')) {
        if (mb_strlen($content) > 1000) {
          $content = mb_substr($content, 0, 997) . '...';
        }
      } else {
        if (strlen($content) > 1000) {
          $content = substr($content, 0, 997) . '...';
        }
      }
    } else {
      // Unknown type or missing user -> skip
      continue;
    }

    // Update row
    $updateStmt->execute([':content'=>$content, ':id'=>$id]);
    $updated++;
  }

  echo json_encode(['ok'=>true,'updated'=>$updated, 'skipped'=>$skipped, 'week'=>$week]);

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error' => $e->getMessage()]);
  exit;
}
