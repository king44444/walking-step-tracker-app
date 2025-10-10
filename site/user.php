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

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e($user['name']) ?> — Lifetime</title>
  <link rel="icon" href="../favicon.ico" />
  <link rel="stylesheet" href="../public/assets/css/app.css" />
  <link rel="stylesheet" href="assets/css/user_awards.css" />
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
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-2 text-center">
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
        </div>
        <div class="mt-2 text-white/70 text-sm">Rank: #<?= (int)$rank ?></div>
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
