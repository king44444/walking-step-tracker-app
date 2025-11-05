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
 require_once __DIR__ . '/../api/lib/awards_settings.php';
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
// awards settings for chip colors
$AWARDS = awards_settings_load();

// Deterministic muted fallback palette (match public JS)
$FALLBACK_PALETTE = ['#6BA3BE','#67A7A1','#78B089','#9EB86A','#C1B35F','#C88F63','#C37479','#B07BAF','#8E86C6','#6F94C1','#72A6AE','#8AA0AA'];

function hex_to_rgb(string $hex): array {
  $m = ltrim(trim($hex), '#');
  if (strlen($m) === 3) {
    $r = hexdec(str_repeat($m[0],2));
    $g = hexdec(str_repeat($m[1],2));
    $b = hexdec(str_repeat($m[2],2));
    return ['r'=>$r,'g'=>$g,'b'=>$b];
  }
  if (strlen($m) === 6) {
    return ['r'=>hexdec(substr($m,0,2)),'g'=>hexdec(substr($m,2,2)),'b'=>hexdec(substr($m,4,2))];
  }
  return ['r'=>120,'g'=>120,'b'=>120];
}

function rgb_to_hex(int $r,int $g,int $b): string {
  $cl = function($n){ $n = max(0,min(255,$n)); return str_pad(dechex($n),2,'0',STR_PAD_LEFT); };
  return '#' . $cl($r) . $cl($g) . $cl($b);
}

function darken_hex(string $hex, float $amount = 0.08): string {
  $c = hex_to_rgb($hex);
  return rgb_to_hex((int)round($c['r']*(1-$amount)), (int)round($c['g']*(1-$amount)), (int)round($c['b']*(1-$amount)));
}

function rel_luminance(array $rgb): float {
  $a = [];
  foreach (['r','g','b'] as $k) {
    $v = $rgb[$k] / 255;
    $a[] = ($v <= 0.03928) ? ($v/12.92) : pow(($v+0.055)/1.055, 2.4);
  }
  return 0.2126*$a[0] + 0.7152*$a[1] + 0.0722*$a[2];
}

function contrast_ratio(string $hex1, string $hex2 = '#FFFFFF'): float {
  $L1 = rel_luminance(hex_to_rgb($hex1));
  $L2 = rel_luminance(hex_to_rgb($hex2));
  $lighter = max($L1,$L2); $darker = min($L1,$L2);
  return ($lighter + 0.05) / ($darker + 0.05);
}

function normalize_label_from_steps(int $steps): string {
  if ($steps >= 1000 && $steps % 1000 === 0) return (string)($steps/1000) . 'k';
  if ($steps === 2500) return '2.5k';
  if ($steps === 5000) return '5k';
  return (string)$steps;
}

function djb2_hash(string $s): int {
  $h = 5381;
  $len = strlen($s);
  for ($i=0; $i<$len; $i++) { $h = (($h << 5) + $h) + ord($s[$i]); }
  return $h & 0x7fffffff; // unsigned
}

function chip_color_for_milestone(array $awards, array $fallbackPalette, string $label, int $steps, ?string $lastHex): array {
  $colors = isset($awards['milestone_colors']) && is_array($awards['milestone_colors']) ? $awards['milestone_colors'] : [];
  $txt = isset($awards['chip_text_color']) && is_string($awards['chip_text_color']) ? $awards['chip_text_color'] : '#FFFFFF';
  $borderOpacity = isset($awards['chip_border_opacity']) && is_numeric($awards['chip_border_opacity']) ? (float)$awards['chip_border_opacity'] : 0.2;

  $keyExact = trim((string)$label);
  $keyNorm = normalize_label_from_steps($steps);

  $bg = $colors[$keyExact] ?? ($colors[$keyNorm] ?? null);
  if (!$bg) {
    $h = djb2_hash($keyExact !== '' ? $keyExact : $keyNorm);
    $idx = $h % max(1, count($fallbackPalette));
    $bg = $fallbackPalette[$idx] ?? '#6BA3BE';
    if ($lastHex && strcasecmp($lastHex, $bg) === 0) {
      $idx = ($idx + 1) % max(1, count($fallbackPalette));
      $bg = $fallbackPalette[$idx] ?? $bg;
    }
  }

  // Ensure contrast with text color
  $safeBg = $bg; $tries = 0;
  while (contrast_ratio($safeBg, $txt) < 4.5 && $tries < 6) { $safeBg = darken_hex($safeBg, 0.08); $tries++; }

  $rgb = hex_to_rgb($safeBg);
  $border = sprintf('rgba(%d, %d, %d, %.3f)', $rgb['r'],$rgb['g'],$rgb['b'],$borderOpacity);
  return ['bg'=>$safeBg, 'text'=>$txt, 'border'=>$border];
}

// awards
$aw = $pdo->prepare('SELECT kind, milestone_value, image_path, created_at FROM ai_awards WHERE user_id = :id ORDER BY created_at ASC');
$aw->execute([':id'=>$id]);
$awards = $aw->fetchAll(PDO::FETCH_ASSOC);

// photo path (resolve relative under site/assets, avoid double 'assets/')
$photo = (string)($user['photo_path'] ?? '');
if ($photo !== '') {
  // If absolute URL, extract path component
  if (preg_match('~^https?://~i', $photo)) {
    $p = parse_url($photo, PHP_URL_PATH) ?: '';
    $photo = $p;
  }
  // Normalize: remove leading slashes and optional leading 'site/'
  $photo = ltrim($photo, '/');
  if (strpos($photo, 'site/') === 0) {
    $photo = substr($photo, 5); // drop 'site/'
  }
  // If it doesn't already start with 'assets/', prefix it
  if (strpos($photo, 'assets/') !== 0) {
    $photo = 'assets/' . $photo;
  }
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
  <?php
    $appCss = $public . '/assets/css/app.css';
    $appCssFs = __DIR__ . '/../public/assets/css/app.css';
    $appCssVer = is_readable($appCssFs) ? (string)filemtime($appCssFs) : (string)time();
  ?>
  <link rel="stylesheet" href="<?= asset($appCss) ?>?v=<?= e($appCssVer) ?>" />
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
      <div class="flex items-center gap-2">
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
        <div class="mt-3">
          <?php if (empty($milestones)): ?>
            <div style="color: rgba(230,236,255,0.6);">No daily milestones configured.</div>
          <?php else: ?>
            <div class="milestones-list rounded-md overflow-hidden">
              <?php $lastChipColor = null; foreach ($milestones as $m):
                $steps = (int)$m['steps'];
                $label = htmlspecialchars($m['label'], ENT_QUOTES, 'UTF-8');
                $count = isset($milestonesCounts[$steps]) ? number_format((int)$milestonesCounts[$steps]) : '0';
                $c = chip_color_for_milestone($AWARDS, $FALLBACK_PALETTE, $label, $steps, $lastChipColor);
                if (empty($c['bg']) || empty($c['text']) || empty($c['border'])) {
                  error_log('[user.php] Empty chip color fields for label=' . $label . ', steps=' . $steps . ' c=' . json_encode($c));
                }
                $lastChipColor = $c['bg'];
              ?>
                <div class="milestone-row flex items-center justify-between px-2 py-1 md:px-4">
                  <div class="milestone-left flex items-center gap-3">
                    <span class="chip milestone-label" data-dynamic style="--chip-bg: <?= e($c['bg']) ?>; --chip-text: <?= e($c['text']) ?>; --chip-border: <?= e($c['border']) ?>;"><?= $label ?></span>
                    <div class="milestone-count font-semibold"><?= $count ?></div>
                  </div>
                  <div class="milestone-steps text-sm text-white/50"><?= number_format($steps) ?> steps</div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="card p-4">
        <div class="kicker">Awards</div>
        <h3 class="text-xl font-bold">Lifetime Awards</h3>
        <div class="space-y-5 mt-4">
          <div>
            <h4 class="text-lg font-semibold">Lifetime Steps</h4>
            <p class="text-sm text-white/60">Lifetime step milestones</p>
            <div id="awards-grid-steps" class="awards-grid"></div>
          </div>
          <div class="pt-1 border-t border-white/10">
            <h4 class="text-lg font-semibold">Lifetime Attendance</h4>
            <p class="text-sm text-white/60">Days reported / checked in</p>
            <div id="awards-grid-attendance" class="awards-grid"></div>
          </div>
        </div>
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
