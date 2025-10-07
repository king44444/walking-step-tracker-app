<?php
declare(strict_types=1);

require __DIR__ . '/../api/util.php';
$pdo = pdo();

function logln(string $msg): void { fwrite(STDOUT, $msg . "\n"); }

function iso_date(string $s): ?string {
  $s = trim($s);
  if ($s === '') return null;
  // Accept YYYY-M-D and pad
  if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $s, $m)) {
    $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
    if (!checkdate($mo, $d, $y)) return null;
    return sprintf('%04d-%02d-%02d', $y, $mo, $d);
  }
  return null;
}

$pdo->exec('BEGIN IMMEDIATE');
$rows = $pdo->query('PRAGMA table_info(weeks)')->fetchAll(PDO::FETCH_ASSOC);
$hasStarts = in_array('starts_on', array_map(fn($r)=>$r['name']??'', $rows), true);
if (!$hasStarts) {
  $pdo->exec("ALTER TABLE weeks ADD COLUMN starts_on TEXT");
}

// Pass 1: pad/normalize any malformed starts_on or week values into starts_on.
$bad = $pdo->query("SELECT rowid, week, starts_on FROM weeks WHERE starts_on IS NULL OR starts_on='' OR starts_on NOT GLOB '____-__-__'")->fetchAll(PDO::FETCH_ASSOC);
$fixed = 0; $skipped = 0;
foreach ($bad as $r) {
  $candidates = [ (string)($r['starts_on'] ?? ''), (string)($r['week'] ?? '') ];
  $iso = null;
  foreach ($candidates as $s) { if ($iso = iso_date($s)) break; }
  if ($iso) {
    $st = $pdo->prepare('UPDATE weeks SET starts_on = :d WHERE rowid = :id');
    $st->execute([':d'=>$iso, ':id'=>$r['rowid']]);
    $fixed++;
  } else {
    $skipped++;
    logln("Skipped rowid {$r['rowid']} (unfixable): week='{$r['week']}', starts_on='{$r['starts_on']}'");
  }
}

// Pass 2: dedupe rows that normalize to the same ISO date; keep a canonical row.
$rows = $pdo->query('SELECT rowid, week, starts_on, label, finalized, finalized_at FROM weeks')->fetchAll(PDO::FETCH_ASSOC);
$groups = [];
foreach ($rows as $r) {
  $iso = iso_date((string)($r['starts_on'] ?? '')) ?? iso_date((string)($r['week'] ?? ''));
  if (!$iso) continue; // skip invalid
  $groups[$iso] = $groups[$iso] ?? [];
  $groups[$iso][] = $r;
}

foreach ($groups as $iso => $rowsForDate) {
  if (count($rowsForDate) <= 1) continue;
  // Choose canonical: prefer finalized row, else first
  usort($rowsForDate, function($a,$b){
    $af = ((int)($a['finalized'] ?? 0)) || (!empty($a['finalized_at']));
    $bf = ((int)($b['finalized'] ?? 0)) || (!empty($b['finalized_at']));
    if ($af === $bf) return 0; return $af ? -1 : 1;
  });
  $keep = $rowsForDate[0];
  $keepId = (int)$keep['rowid'];
  // Ensure canonical row has starts_on normalized and label set
  $label = trim((string)($keep['label'] ?? '')) ?: $iso;
  $pdo->prepare('UPDATE weeks SET starts_on = :d, label = :l WHERE rowid = :id')->execute([':d'=>$iso, ':l'=>$label, ':id'=>$keepId]);
  // For the rest, move entries then delete
  for ($i=1; $i<count($rowsForDate); $i++) {
    $r = $rowsForDate[$i];
    $rid = (int)$r['rowid'];
    $cands = array_filter([(string)($r['week'] ?? ''), (string)($r['starts_on'] ?? '')]);
    foreach ($cands as $w) {
      $pdo->prepare('UPDATE entries SET week = :iso WHERE week = :w')->execute([':iso'=>$iso, ':w'=>$w]);
    }
    $pdo->prepare('DELETE FROM weeks WHERE rowid = :id')->execute([':id'=>$rid]);
  }
}

$pdo->exec('COMMIT');

// Create indexes after cleanup
$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS weeks_starts_on_uq ON weeks(starts_on)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_weeks_starts_on ON weeks(starts_on)');

logln("Repaired weeks: fixed={$fixed}, skipped={$skipped}");
