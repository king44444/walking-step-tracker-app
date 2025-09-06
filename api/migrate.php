<?php
declare(strict_types=1);
require __DIR__ . '/db.php';

/* tables:
   - weeks(week PK, label, finalized, created_at, finalized_at)
   - entries(id PK, week, name, monday..saturday, sex, age, tag, updated_at)
   - snapshots(week PK FK->weeks.week, json, created_at)
*/

$pdo->exec("
CREATE TABLE IF NOT EXISTS weeks (
  week TEXT PRIMARY KEY,
  label TEXT,
  finalized INTEGER DEFAULT 0,
  created_at TEXT DEFAULT (datetime('now')),
  finalized_at TEXT
);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  week TEXT NOT NULL,
  name TEXT NOT NULL,
  monday INTEGER CHECK(monday IS NULL OR monday >= 0),
  tuesday INTEGER CHECK(tuesday IS NULL OR tuesday >= 0),
  wednesday INTEGER CHECK(wednesday IS NULL OR wednesday >= 0),
  thursday INTEGER CHECK(thursday IS NULL OR thursday >= 0),
  friday INTEGER CHECK(friday IS NULL OR friday >= 0),
  saturday INTEGER CHECK(saturday IS NULL OR saturday >= 0),
  sex TEXT,
  age INTEGER,
  tag TEXT,
  updated_at TEXT DEFAULT (datetime('now')),
  CONSTRAINT fk_entries_week FOREIGN KEY (week) REFERENCES weeks(week) ON DELETE CASCADE
);
");

// Add per-day first-report timestamp columns (nullable integers) if missing.
// These store the unix epoch seconds (UTC) for the first time a day's value
// was set to a positive integer for a given week/name.
$cols = $pdo->query("PRAGMA table_info(entries)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
$reportCols = [
  'mon_reported_at','tue_reported_at','wed_reported_at',
  'thu_reported_at','fri_reported_at','sat_reported_at'
];
foreach ($reportCols as $c) {
  if (!in_array($c, $colNames, true)) {
    // ALTER TABLE ADD COLUMN is safe and idempotent when guarded above.
    $pdo->exec("ALTER TABLE entries ADD COLUMN $c INTEGER");
  }
}

// Create triggers to set the reported_at timestamp only the first time a day
// transitions from NULL/0 to a positive integer. We create an AFTER INSERT
// trigger (for new rows) and an AFTER UPDATE OF <day> trigger (for updates).
$days = [
  ['day'=>'monday','rep'=>'mon_reported_at'],
  ['day'=>'tuesday','rep'=>'tue_reported_at'],
  ['day'=>'wednesday','rep'=>'wed_reported_at'],
  ['day'=>'thursday','rep'=>'thu_reported_at'],
  ['day'=>'friday','rep'=>'fri_reported_at'],
  ['day'=>'saturday','rep'=>'sat_reported_at']
];

$stmtExists = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type='trigger' AND name = ? LIMIT 1");
foreach ($days as $d) {
  $day = $d['day']; $rep = $d['rep'];
  // UPDATE trigger
  $trgUpd = "trg_entries_set_{$day}_reported_at_update";
  $stmtExists->execute([$trgUpd]);
  if (!$stmtExists->fetchColumn()) {
    $sql = "
    CREATE TRIGGER $trgUpd
    AFTER UPDATE OF $day ON entries
    WHEN NEW.$day IS NOT NULL AND (OLD.$day IS NULL OR OLD.$day = 0) AND NEW.$day != OLD.$day AND NEW.$rep IS NULL
    BEGIN
      UPDATE entries SET $rep = CAST(strftime('%s','now') AS INTEGER)
      WHERE week = NEW.week AND name = NEW.name;
    END;
    ";
    $pdo->exec($sql);
  }

  // INSERT trigger
  $trgIns = "trg_entries_set_{$day}_reported_at_insert";
  $stmtExists->execute([$trgIns]);
  if (!$stmtExists->fetchColumn()) {
    $sql2 = "
    CREATE TRIGGER $trgIns
    AFTER INSERT ON entries
    WHEN NEW.$day IS NOT NULL AND NEW.$day > 0 AND NEW.$rep IS NULL
    BEGIN
      UPDATE entries SET $rep = CAST(strftime('%s','now') AS INTEGER)
      WHERE week = NEW.week AND name = NEW.name;
    END;
    ";
    $pdo->exec($sql2);
  }
}

/* Do a quick integrity check; log problems but do not echo to output.
   We avoid ALTER TABLE for existing installs to keep migration safe on Pi.
   New installs will get the CHECK constraints above. */
try {
  $res = $pdo->query("PRAGMA integrity_check")->fetchColumn();
  if ($res !== 'ok') {
    error_log("migrate.php: PRAGMA integrity_check returned: " . $res);
  }
} catch (Throwable $e) {
  error_log("migrate.php: integrity_check failed: " . $e->getMessage());
}

$pdo->exec("CREATE INDEX IF NOT EXISTS idx_entries_week_name ON entries(week, name);");
$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS entries_week_name_uq ON entries(week, name);");

$pdo->exec("
CREATE TABLE IF NOT EXISTS snapshots (
  week TEXT PRIMARY KEY,
  json TEXT NOT NULL,
  created_at TEXT DEFAULT (datetime('now')),
  CONSTRAINT fk_snapshots_week FOREIGN KEY (week) REFERENCES weeks(week) ON DELETE CASCADE
);
");

$pdo->exec("
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY,
  name TEXT NOT NULL,
  sex TEXT,
  age INTEGER,
  tag TEXT
);
");

$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS users_name_uq ON users(name)");
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);

// phone_e164 (idempotent)
$hasPhone = false;
foreach ($cols as $c) { if (isset($c['name']) && $c['name'] === 'phone_e164') { $hasPhone = true; break; } }
if (!$hasPhone) { $pdo->exec("ALTER TABLE users ADD COLUMN phone_e164 TEXT"); }

// photo_path and photo_consent (idempotent)
$hasPhotoPath = false;
$hasPhotoConsent = false;
foreach ($cols as $c) {
  if (isset($c['name'])) {
    if ($c['name'] === 'photo_path') $hasPhotoPath = true;
    if ($c['name'] === 'photo_consent') $hasPhotoConsent = true;
  }
}
if (!$hasPhotoPath) { $pdo->exec("ALTER TABLE users ADD COLUMN photo_path TEXT"); }
if (!$hasPhotoConsent) { $pdo->exec("ALTER TABLE users ADD COLUMN photo_consent INTEGER DEFAULT 0"); }

$pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS users_phone_e164_uq ON users(phone_e164)");

$pdo->exec("
CREATE TABLE IF NOT EXISTS sms_audit(
  id INTEGER PRIMARY KEY,
  created_at TEXT NOT NULL,
  from_number TEXT NOT NULL,
  raw_body TEXT NOT NULL,
  parsed_day TEXT,
  parsed_steps INTEGER,
  resolved_week TEXT,
  resolved_day TEXT,
  status TEXT
);
");

/* Backfill users from distinct entry names, if not already present */
$pdo->exec("
INSERT INTO users(name, sex, age, tag)
SELECT e.name,
       (SELECT sex FROM entries e2 WHERE e2.name=e.name AND e2.sex IS NOT NULL ORDER BY updated_at DESC LIMIT 1),
       (SELECT age FROM entries e2 WHERE e2.name=e.name AND e2.age IS NOT NULL ORDER BY updated_at DESC LIMIT 1),
       (SELECT tag FROM entries e2 WHERE e2.name=e.name AND e2.tag IS NOT NULL ORDER BY updated_at DESC LIMIT 1)
FROM (SELECT DISTINCT name FROM entries WHERE name IS NOT NULL AND name != '') e
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.name = e.name);
");

$pdo->exec("
CREATE VIEW IF NOT EXISTS lifetime_stats AS
SELECT
  u.name AS name,
  COALESCE(u.sex, '') AS sex,
  u.age AS age,
  COALESCE(u.tag, '') AS tag,
  COALESCE((
    SELECT SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0))
    FROM entries e WHERE e.name = u.name
  ),0) AS total_steps,
  COALESCE((
    SELECT COUNT(1)
    FROM entries e WHERE e.name = u.name AND (
      COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursday,0)+COALESCE(friday,0)+COALESCE(saturday,0)
    ) > 0
  ),0) AS weeks_with_data
FROM users u
WHERE u.is_active = 1
ORDER BY total_steps DESC, name ASC;
");
