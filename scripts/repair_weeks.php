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

$bad = $pdo->query("SELECT rowid, week, starts_on FROM weeks WHERE starts_on IS NULL OR length(starts_on) != 10 OR starts_on NOT GLOB '____-__-__'")->fetchAll(PDO::FETCH_ASSOC);
$fixed = 0; $skipped = 0;
foreach ($bad as $r) {
  $source = $r['starts_on'] ?: ($r['week'] ?? '');
  $iso = iso_date($source);
  if (!$iso) $iso = iso_date((string)($r['week'] ?? ''));
  if ($iso) {
    $st = $pdo->prepare('UPDATE weeks SET starts_on = :d WHERE rowid = :id');
    $st->execute([':d'=>$iso, ':id'=>$r['rowid']]);
    $fixed++;
  } else {
    $skipped++;
    logln("Skipped rowid {$r['rowid']} (unfixable): week='{$r['week']}', starts_on='{$r['starts_on']}'");
  }
}
$pdo->exec('COMMIT');

$pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS weeks_starts_on_uq ON weeks(starts_on)');
$pdo->exec('CREATE INDEX IF NOT EXISTS idx_weeks_starts_on ON weeks(starts_on)');

logln("Repaired weeks: fixed={$fixed}, skipped={$skipped}");

