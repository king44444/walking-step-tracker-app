<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
\App\Core\Env::bootstrap(dirname(__DIR__));

use App\Config\DB;

function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$pdo = DB::pdo();
ob_start(); require_once __DIR__ . '/../api/migrate.php'; ob_end_clean();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); echo 'Bad id'; exit; }

$u = $pdo->prepare('SELECT id,name,sex,age,tag,photo_path FROM users WHERE id = :id');
$u->execute([':id'=>$id]);
$user = $u->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(404); echo 'User not found'; exit; }

// Lifetime totals
$name = (string)$user['name'];
$sum = $pdo->prepare("SELECT 
  COALESCE(SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)),0) AS total,
  SUM((CASE WHEN monday IS NOT NULL THEN 1 ELSE 0 END)
      +(CASE WHEN tuesday IS NOT NULL THEN 1 ELSE 0 END)
      +(CASE WHEN wednesday IS NOT NULL THEN 1 ELSE 0 END)
      +(CASE WHEN thursday IS NOT NULL THEN 1 ELSE 0 END)
      +(CASE WHEN friday IS NOT NULL THEN 1 ELSE 0 END)
      +(CASE WHEN saturday IS NOT NULL THEN 1 ELSE 0 END)) AS days,
  COALESCE(MAX(COALESCE(monday,0)),0) AS max_mon,
  COALESCE(MAX(COALESCE(tuesday,0)),0) AS max_tue,
  COALESCE(MAX(COALESCE(wednesday,0)),0) AS max_wed,
  COALESCE(MAX(COALESCE(thursday,0)),0) AS max_thu,
  COALESCE(MAX(COALESCE(friday,0)),0) AS max_fri,
  COALESCE(MAX(COALESCE(saturday,0)),0) AS max_sat
  FROM entries WHERE name = :n");
$sum->execute([':n'=>$name]);
$row = $sum->fetch(PDO::FETCH_ASSOC) ?: ['total'=>0,'days'=>0,'max_mon'=>0,'max_tue'=>0,'max_wed'=>0,'max_thu'=>0,'max_fri'=>0,'max_sat'=>0];
$total = (int)$row['total'];
$days  = (int)$row['days'];
$best  = max((int)$row['max_mon'],(int)$row['max_tue'],(int)$row['max_wed'],(int)$row['max_thu'],(int)$row['max_fri'],(int)$row['max_sat']);

// weeks participated (>0 total in week)
$stWeeks = $pdo->prepare("SELECT COUNT(1) FROM entries WHERE name = :n AND 
  (COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)) > 0");
$stWeeks->execute([':n'=>$name]);
$weeks = (int)$stWeeks->fetchColumn();
$avg = $days > 0 ? (int)round($total / $days) : 0;

// rank
$stTotals = $pdo->query("SELECT name,
    COALESCE((SELECT SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)) FROM entries e WHERE e.name = u.name),0) AS total
  FROM users u");
$higher = 0; foreach ($stTotals->fetchAll(PDO::FETCH_ASSOC) as $t) { if ((int)$t['total'] > $total) $higher++; }
$rank = $higher + 1;

 // Dynamic daily milestones driven by admin setting 'daily.milestones'
 // Read admin setting; fallback to site/config.json public defaults
 require_once __DIR__ . '/../api/lib/settings.php';
 $rawMilestones = setting_get('daily.milestones', null);
 if ($rawMilestones && is_string($rawMilestones) && trim($rawMilestones) !== '') {
   $parsedMilestones = json_decode($rawMilestones, true);
 } else {
   $cfgJson = @file_get_contents(__DIR__ . '/config.json');
   $cfg = $cfgJson ? json_decode($cfgJson, true) : [];
   $parsedMilestones = $cfg['daily_milestones'] ?? $cfg['dailyMilestones'] ?? $cfg['DAILY_MILESTONES'] ?? [];
 }
 $milestones = [];
 if (is_array($parsedMilestones)) {
   foreach ($parsedMilestones as $it) {
     if (!is_array($it)) continue;
     $steps = isset($it['steps']) ? (int)$it['steps'] : 0;
     $label = isset($it['label']) ? trim((string)$it['label']) : '';
     if ($steps > 0 && $label !== '') $milestones[] = ['steps'=>$steps, 'label'=>$label];
   }
 }
 // sort ascending by steps
 usort($milestones, function($a,$b){ return $a['steps'] <=> $b['steps']; });

 // Build single SQL that computes a column per milestone (single table scan)
 $milestonesCounts = [];
 if (!empty($milestones)) {
   $cols = [];
   $binds = [':n' => $name];
   foreach ($milestones as $i => $m) {
     $param = ':t' . $i;
     $cols[] = "SUM((CASE WHEN monday IS NOT NULL AND monday >= {$param} THEN 1 ELSE 0 END)"
            . " + (CASE WHEN tuesday IS NOT NULL AND tuesday >= {$param} THEN 1 ELSE 0 END)"
            . " + (CASE WHEN wednesday IS NOT NULL AND wednesday >= {$param} THEN 1 ELSE 0 END)"
            . " + (CASE WHEN thursday IS NOT NULL AND thursday >= {$param} THEN 1 ELSE 0 END)"
            . " + (CASE WHEN friday IS NOT NULL AND friday >= {$param} THEN 1 ELSE 0 END)"
            . " + (CASE WHEN saturday IS NOT NULL AND saturday >= {$param} THEN 1 ELSE 0 END)) AS c{$i}";
     $binds[$param] = $m['steps'];
   }
   $sql = "SELECT\n  " . implode(",\n  ", $cols) . "\nFROM entries WHERE name = :n";
   $stmt = $pdo->prepare($sql);
   $stmt->execute($binds);
   $row = $stmt->fetch(PDO::FETCH_NUM);
   if ($row !== false) {
     foreach ($row as $i => $val) {
       $milestonesCounts[$milestones[$i]['steps']] = (int)$val;
     }
   } else {
     foreach ($milestones as $m) $milestonesCounts[$m['steps']] = 0;
   }
 } 

// small helper to pick chip color classes that match the dashboard JS
function badge_class_for_steps(int $steps): array {
  if ($steps >= 30000) return ['bg'=>'bg-emerald-500/15','text'=>'text-emerald-300'];
  if ($steps >= 20000) return ['bg'=>'bg-yellow-500/15','text'=>'text-yellow-300'];
  if ($steps >= 15000) return ['bg'=>'bg-lime-500/15','text'=>'text-lime-300'];
  if ($steps >= 10000) return ['bg'=>'bg-green-500/15','text'=>'text-green-300'];
  if ($steps >= 2500)  return ['bg'=>'bg-cyan-500/15','text'=>'text-cyan-300'];
  if ($steps >= 1000)  return ['bg'=>'bg-blue-500/15','text'=>'text-blue-300'];
  return ['bg'=>'bg-white/5','text'=>'text-white/70'];
}

// awards
$aw = $pdo->prepare('SELECT kind, milestone_value, image_path, created_at FROM ai_awards WHERE user_id = :id ORDER BY created_at ASC');
$aw->execute([':id'=>$id]);
$awards = $aw->fetchAll(PDO::FETCH_ASSOC);

// photo path (resolve relative under site/assets)
$photo = (string)($user['photo_path'] ?? '');
if ($photo) {
  if (preg_match('~^https?://~i', $photo)) { $p = parse_url($photo, PHP_URL_PATH) ?: $photo; $photo = $p; }
  $photo = ltrim(preg_replace('#^site/#','', preg_replace('#^/+#','',$photo)), '/');
  $photo = 'assets/' . ltrim($photo, '/');
}
if ($photo === '') { $photo = 'assets/admin/no-photo.svg'; }

 // compute dynamic base paths so site works under any mount point (e.g. /dev/html/walk)
$script = $_SERVER['SCRIPT_NAME'] ?? '';
// expect script like /dev/html/walk/site/user.php — remove /site/... to get root
$siteDir = '/site';
$root = preg_replace('#' . preg_quote($siteDir) . '/.*$#', '', $script);
$root = rtrim($root, '/'); // e.g. /dev/html/walk or ''
$public = ($root !== '' ? $root : '') . '/public';
$site = ($root !== '' ? $root : '') . '/site';
function asset($p){ return htmlspecialchars((string)$p, ENT_QUOTES, 'UTF-8'); }
?>

<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($user['name']) ?> — Lifetime</title>
  <link rel="icon" href="../favicon.ico" />
  <link rel="stylesheet" href="<?= asset($public . '/assets/css/app.css') ?>" />
  <link rel="stylesheet" href="<?= asset($site . '/assets/css/user_awards.css') ?>" />
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-screen">
  <header class="px-4 py-4 sm:px-6 sm:py-5 sticky top-0 backdrop-blur supports-[backdrop-filter]:bg-[#0b1020]/80 z-30 border-b border-white/10">
    <div class="max-w-6xl mx-auto flex items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <img src="<?= e($photo) ?>" alt="photo" class="w-12 h-12 rounded-full object-cover border border-white/10">
        <div>
          <div class="kicker">User Profile</div>
          <h1 class="text-2xl sm:text-3xl font-extrabold leading-tight"><?= e($user['name']) ?> <?= $user['tag'] ? '<span class="text-white/60 text-base">('.e($user['tag']).')</span>' : '' ?></h1>
        </div>
      </div>
      <div class="hidden sm:flex items-center gap-2">
        <a href="./" class="btn">← Back</a>
      </div>
    </div>
  </header>

  <main class="max-w-6xl mx-auto px-4 sm:px-6 py-4 sm:py-6 space-y-4 sm:space-y-6">
    <section class="grid-auto-fit">
      <div class="card p-4">
        <div class="kicker">Lifetime</div>
        <h3 class="text-xl font-bold">Totals</h3>
        <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mt-2 text-center">
          <div class="bg-white/5 rounded-lg p-3">
            <div class="text-xs text-white/60">Total Steps</div>
            <div class="text-2xl font-extrabold stat"><?= number_format($total) ?></div>
          </div>
          <div class="bg-white/5 rounded-lg p-3">
            <div class="text-xs text-white/60">Avg / Day</div>
            <div class="text-2xl font-extrabold stat"><?= number_format($avg) ?></div>
          </div>
          <div class="bg-white/5 rounded-lg p-3">
            <div class="text-xs text-white/60">Best Day</div>
            <div class="text-2xl font-extrabold stat"><?= number_format($best) ?></div>
          </div>
          <div class="bg-white/5 rounded-lg p-3">
            <div class="text-xs text-white/60">Weeks</div>
            <div class="text-2xl font-extrabold stat"><?= number_format($weeks) ?></div>
          </div>
          <div class="bg-white/5 rounded-lg p-3">
            <div class="text-xs text-white/60">Days</div>
            <div class="text-2xl font-extrabold stat"><?= number_format($days) ?></div>
          </div>
        </div>
        <div class="mt-2 text-white/70 text-sm">Rank: #<?= (int)$rank ?></div>
      </div>

      <div class="card p-4">
        <div class="kicker">Milestones</div>
        <h3 class="text-xl font-bold">Daily Milestone Counts</h3>
        <div class="mt-3 flex flex-wrap gap-2 items-start">
          <?php if (empty($milestones)): ?>
            <div style="grid-column: 1 / -1; color: rgba(230,236,255,0.6);">No daily milestones configured.</div>
          <?php else: ?>
            <div class="flex-1 flex flex-wrap gap-2">
              <?php foreach ($milestones as $m):
                $steps = (int)$m['steps'];
                $label = htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8');
                $count = isset($milestonesCounts[$steps]) ? number_format((int)$milestonesCounts[$steps]) : '0';
                $cls = badge_class_for_steps($steps);
              ?>
                <div class="flex items-center gap-2">
                  <span class="chip <?= $cls['bg'] ?> <?= $cls['text'] ?>"><?= $label ?></span>
                  <div class="text-sm font-semibold"><?= $count ?></div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="w-full md:w-56 mt-4 md:mt-0 text-sm text-white/60">
              <div class="font-semibold text-white/80 mb-2">Legend</div>
              <?php foreach ($milestones as $m):
                $steps = (int)$m['steps'];
                $label = htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8');
                $cls = badge_class_for_steps($steps);
              ?>
                <div class="flex items-center gap-3 mb-2">
                  <span class="chip <?= $cls['bg'] ?> <?= $cls['text'] ?>"><?= $label ?></span>
                  <div class="text-white/60"><?= number_format($steps) ?> steps</div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card p-4">
        <div class="kicker">Awards</div>
        <h3 class="text-xl font-bold">Lifetime Awards</h3>
        <div id="awards-grid"></div>
      </div>
    </section>
  </main>

  <!-- Lightbox -->
  <div id="lightbox" class="lightbox" hidden>
    <div class="lightbox-backdrop"></div>
    <div class="lightbox-content">
      <button class="lightbox-close" aria-label="Close">×</button>
      <button class="lightbox-prev" aria-label="Previous award">‹</button>
      <img id="lb-img" class="lightbox-image" alt="" />
      <div class="lightbox-caption">
        <div class="lightbox-title"></div>
        <div class="lightbox-date"></div>
      </div>
      <button class="lightbox-next" aria-label="Next award">›</button>
    </div>
  </div>

  <script src="assets/js/user_awards.js"></script>
  <script>
    // Initialize awards system on page load
    initUserAwards(<?= (int)$id ?>);
  </script>
</body>
</html>
