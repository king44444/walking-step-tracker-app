<?php
declare(strict_types=1);

// DEPRECATED: This endpoint will be removed; use router /api/... instead
header('X-Deprecated: This endpoint will be removed; use router /api/... instead');
require_once __DIR__ . '/../vendor/autoload.php';
App\Core\Env::bootstrap(dirname(__DIR__));
use App\Config\DB;
$pdo = DB::pdo();

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

// AI scaffolding: idempotent tables, columns, and indexes for offline AI features.
// - app_settings
// - additional users columns: ai_opt_in, interests, rival_id
// - user_ai_profile
// - ai_messages (+ indexes)
// - ai_awards (+ index)
$pdo->exec("
CREATE TABLE IF NOT EXISTS app_settings (
  key TEXT PRIMARY KEY,
  value TEXT
);
");

// Add new user columns if missing (idempotent)
$cols = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
$colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);

if (!in_array('ai_opt_in', $colNames, true)) {
  $pdo->exec("ALTER TABLE users ADD COLUMN ai_opt_in INTEGER DEFAULT 0");
}
if (!in_array('interests', $colNames, true)) {
  $pdo->exec("ALTER TABLE users ADD COLUMN interests TEXT");
}
if (!in_array('rival_id', $colNames, true)) {
  $pdo->exec("ALTER TABLE users ADD COLUMN rival_id INTEGER");
}
// SMS reminders support
if (!in_array('reminders_enabled', $colNames, true)) {
  $pdo->exec("ALTER TABLE users ADD COLUMN reminders_enabled INTEGER DEFAULT 0");
}
if (!in_array('reminders_when', $colNames, true)) {
  $pdo->exec("ALTER TABLE users ADD COLUMN reminders_when TEXT");
}

// Per-user AI profile table
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_ai_profile (
  user_id INTEGER PRIMARY KEY,
  tone TEXT,
  fun_facts TEXT,
  do_not_use TEXT,
  last_reviewed_at TEXT,
  CONSTRAINT fk_user_ai_profile_user FOREIGN KEY (user_id) REFERENCES users(id)
);
");

// AI-generated messages tracking
$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_messages (
  id INTEGER PRIMARY KEY,
  type TEXT NOT NULL,
  scope_key TEXT,
  user_id INTEGER,
  week TEXT,
  content TEXT NOT NULL,
  model TEXT NOT NULL,
  prompt_hash TEXT,
  approved_by TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  sent_at TEXT,
  CONSTRAINT fk_ai_messages_user FOREIGN KEY (user_id) REFERENCES users(id)
);
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_messages_week ON ai_messages(week);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_messages_user ON ai_messages(user_id);");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_messages_sendable ON ai_messages(approved_by, sent_at);");

// Extend ai_messages with provider/model/raw_json/cost_usd if missing (idempotent)
try {
  $cols = $pdo->query("PRAGMA table_info(ai_messages)")->fetchAll(PDO::FETCH_ASSOC);
  $colNames = array_map(function($c){ return $c['name'] ?? ''; }, $cols);
  if (!in_array('provider', $colNames, true)) { $pdo->exec("ALTER TABLE ai_messages ADD COLUMN provider TEXT"); }
  if (!in_array('raw_json', $colNames, true)) { $pdo->exec("ALTER TABLE ai_messages ADD COLUMN raw_json TEXT"); }
  if (!in_array('cost_usd', $colNames, true)) { $pdo->exec("ALTER TABLE ai_messages ADD COLUMN cost_usd REAL"); }
} catch (Throwable $e) {
  error_log('migrate.php: ai_messages alter failed: ' . $e->getMessage());
}

// Seed ai_autosend setting if missing
try { $pdo->exec("INSERT OR IGNORE INTO settings(key,value) VALUES('ai_autosend','0')"); } catch (Throwable $e) {}

// AI awards / milestones
$pdo->exec("
CREATE TABLE IF NOT EXISTS ai_awards (
  id INTEGER PRIMARY KEY,
  user_id INTEGER NOT NULL,
  kind TEXT NOT NULL,
  milestone_value INTEGER NOT NULL,
  week TEXT,
  image_path TEXT,
  meta TEXT,
  created_at TEXT DEFAULT (datetime('now')),
  CONSTRAINT fk_ai_awards_user FOREIGN KEY (user_id) REFERENCES users(id)
);
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_ai_awards_user ON ai_awards(user_id);");

// User awards cache table for computed award dates (idempotent)
$pdo->exec("
CREATE TABLE IF NOT EXISTS user_awards_cache (
  user_id INTEGER NOT NULL,
  award_key TEXT NOT NULL,
  threshold INTEGER NOT NULL,
  awarded_at TEXT NOT NULL,
  PRIMARY KEY (user_id, award_key),
  FOREIGN KEY (user_id) REFERENCES users(id)
);
");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_awardscache_user ON user_awards_cache(user_id);");

// Global settings table (idempotent). Simple key/value store.
// Prompt 1 — Add AI toggle field support via `settings` table
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT)");
  // Add updated_at column if missing
  try {
    $cols = $pdo->query("PRAGMA table_info(settings)")->fetchAll(PDO::FETCH_ASSOC);
    $names = array_map(fn($c)=>$c['name']??'', $cols);
    if (!in_array('updated_at', $names, true)) { $pdo->exec("ALTER TABLE settings ADD COLUMN updated_at TEXT"); }
  } catch (Throwable $e) {}
  // Seed canonical keys if missing
  $pdo->exec("INSERT OR IGNORE INTO settings(key, value, updated_at) VALUES('ai.enabled','1',datetime('now'))");
  $pdo->exec("INSERT OR IGNORE INTO settings(key, value, updated_at) VALUES('ai.nudge.enabled','1',datetime('now'))");
  $pdo->exec("INSERT OR IGNORE INTO settings(key, value, updated_at) VALUES('ai.recap.enabled','1',datetime('now'))");
  $pdo->exec("INSERT OR IGNORE INTO settings(key, value, updated_at) VALUES('ai.award.enabled','1',datetime('now'))");
} catch (Throwable $e) {
  error_log('migrate.php: settings table setup failed: ' . $e->getMessage());
}

// Backfill AI awards (idempotent).
// - lifetime_steps milestones: 100000, 250000, 500000, 1000000
// - attendance_weeks milestones: 25, 50, 100
// This runs safely on existing installs and will only insert missing awards.
try {
  $stmtUsers = $pdo->query("
    SELECT u.id, u.name,
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
  ")->fetchAll(PDO::FETCH_ASSOC);

  $checkStmt = $pdo->prepare("SELECT 1 FROM ai_awards WHERE user_id = :uid AND kind = :kind AND milestone_value = :val LIMIT 1");
  $insStmt = $pdo->prepare("INSERT INTO ai_awards (user_id, kind, milestone_value, week, image_path, meta) VALUES (:uid, :kind, :val, NULL, :img, NULL)");

  $lifetimeThresholds = [100000, 250000, 500000, 1000000];
  $attendanceThresholds = [25, 50, 100];

  foreach ($stmtUsers as $u) {
    $uid = (int)($u['id'] ?? 0);
    if ($uid === 0) continue;
    $total = (int)($u['total_steps'] ?? 0);
    $weeks = (int)($u['weeks_with_data'] ?? 0);

    foreach ($lifetimeThresholds as $t) {
      if ($total >= $t) {
        $checkStmt->execute([':uid'=>$uid, ':kind'=>'lifetime_steps', ':val'=>$t]);
        if (!$checkStmt->fetchColumn()) {
          $insStmt->execute([':uid'=>$uid, ':kind'=>'lifetime_steps', ':val'=>$t, ':img'=>null]);
        }
      }
    }

    foreach ($attendanceThresholds as $t) {
      if ($weeks >= $t) {
        $checkStmt->execute([':uid'=>$uid, ':kind'=>'attendance_weeks', ':val'=>$t]);
        if (!$checkStmt->fetchColumn()) {
          $insStmt->execute([':uid'=>$uid, ':kind'=>'attendance_weeks', ':val'=>$t, ':img'=>null]);
        }
      }
    }
  }
} catch (Throwable $e) {
  error_log("migrate.php: ai_awards backfill failed: " . $e->getMessage());
}

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
$pdo->exec("CREATE TABLE IF NOT EXISTS sms_outbound_audit(
  id INTEGER PRIMARY KEY,
  created_at TEXT,
  to_number TEXT,
  body TEXT,
  http_code INTEGER,
  sid TEXT,
  error TEXT
)");

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
