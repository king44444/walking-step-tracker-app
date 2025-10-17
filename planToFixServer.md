 Here’s a safe, data‑only recovery plan to correct the “open week” and fix any entries
  recorded into a future week, without losing step counts. It is conservative, reversible,
  and handles edge cases like duplicate rows.

  Important: Do not change code. We’ll back up the DB, audit, then move only data. All
  writes happen inside a single transaction so you can roll back if anything looks off.

  Overview

  - Back up the SQLite DB with a proper hot backup.
  - Determine the correct “current Monday” in WALK_TZ.
  - Identify future “open” week(s) mistakenly used (e.g., 2025-10-19/2025-10-20).
  - Check for duplicates between the wrong future week and the correct week.
  - If no duplicates: move rows by updating entries.week to the correct week.
  - If duplicates exist: merge carefully (copy only missing day columns), then delete the
  future-week rows for those names.
  - Ensure a week row exists for the correct Monday.
  - Clear award-date cache for affected users so awarded_at recomputes correctly.
  - Verify totals, awarded dates, and absence of future-week entries.
  - Commit or roll back.

  Prep and Backup

  - SSH and go to the app:
      - ssh deploy@example-host
      - cd /var/www/your-app
  - Create a timestamped backup (uses sqlite’s safe .backup):
      - mkdir -p data/backup
      - ts=$(date +%Y%m%d_%H%M%S)
      - sqlite3 data/walkweek.sqlite ".backup 'data/backup/walkweek_$ts.sqlite'"
      - ls -lh data/backup/walkweek_$ts.sqlite
  - Optional: brief maintenance window reduces concurrent writes (cron reminders touch DB
  each minute). If possible, do this in a quiet minute; our transaction will lock writes
  while we fix.

  Determine the correct current week (Monday) in WALK_TZ

  - Compute “current Monday” (the start of this week) in configured timezone:
      - php -r "require 'vendor/autoload.php'; \App\Core\Env::bootstrap('.'); $tz=new
  DateTimeZone(getenv('WALK_TZ')?:'America/Denver'); $now=new DateTime('now',$tz);
  $w=(int)$now->format('N'); $mon=(clone $now)->modify('-'.($w-1).' days'); echo
  $mon->format('Y-m-d');"
  - Save that output as CUR_MON. Example: CUR_MON=2025-10-13.

  Audit weeks and find the wrong “open” future week(s)

  - List weeks, newest first:
      - sqlite3 data/walkweek.sqlite "SELECT week, COALESCE(label, week) AS label,
  COALESCE(finalized,0) AS finalized FROM weeks ORDER BY week DESC;"
  - Identify any unfinalized rows with week > '$CUR_MON'. Example offenders: 2025-10-19 or
  2025-10-20.
  - For each offending week W_OFF, count entries:
      - sqlite3 data/walkweek.sqlite "SELECT COUNT(*) FROM entries WHERE week='W_OFF';"
  - Preview names under W_OFF:
      - sqlite3 -newline $'\n' data/walkweek.sqlite "SELECT name FROM entries WHERE
  week='W_OFF' ORDER BY name;"

  Check for duplicates against the correct week

  - Ensure the correct week row exists:
      - sqlite3 data/walkweek.sqlite "SELECT 1 FROM weeks WHERE week='CUR_MON' LIMIT 1;"
      - If no row, we’ll insert it during the fix.
  - Detect any names with rows in both W_OFF and CUR_MON (potential merge needed):
      - sqlite3 data/walkweek.sqlite "SELECT e1.name FROM entries e1 JOIN entries e2 ON
  e2.week='CUR_MON' AND e2.name=e1.name WHERE e1.week='W_OFF' ORDER BY e1.name;"
  - Save this list. If it’s empty, we’re in the easy path. If not, we’ll do a careful
  per‑day merge only where the target is null.

  Dry‑run totals check (before write)

  - Total steps globally:
      - sqlite3 data/walkweek.sqlite "SELECT
  COALESCE(SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursd
  ay,0)+COALESCE(friday,0)+COALESCE(saturday,0)),0) FROM entries;"
  - Totals by week for W_OFF and CUR_MON:
      - sqlite3 data/walkweek.sqlite "SELECT week,
  COALESCE(SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursd
  ay,0)+COALESCE(friday,0)+COALESCE(saturday,0)),0) AS total FROM entries WHERE week IN
  ('W_OFF','CUR_MON') GROUP BY week;"

  Safe data fix (single transaction)

  - We’ll run a single transaction to:
      - Create weeks row for CUR_MON if missing.
      - Merge duplicates: copy only missing day values from W_OFF → CUR_MON, day by day.
      - Move non-duplicate rows by updating week.
      - Delete now-empty duplicate source rows on W_OFF.
  - Start sqlite shell to ensure ROLLBACK is possible if anything looks wrong:
      - sqlite3 data/walkweek.sqlite
  - Paste the following block; replace CUR_MON and W_OFF with actual values. If you have
  multiple W_OFF, repeat the merge/move/delete blocks per W_OFF.

  BEGIN IMMEDIATE;

  -- 1) Ensure target week exists
  INSERT INTO weeks(week, label, finalized)
  SELECT 'CUR_MON', 'CUR_MON', 0
  WHERE NOT EXISTS (SELECT 1 FROM weeks WHERE week='CUR_MON');

  -- 2) Merge duplicates (only copy days that are NULL on target)
  -- Repeat this block for each day column
  UPDATE entries AS t
  SET monday = COALESCE(t.monday, s.monday)
  FROM entries AS s
  WHERE t.week='CUR_MON' AND s.week='W_OFF' AND t.name=s.name;

  UPDATE entries AS t
  SET tuesday = COALESCE(t.tuesday, s.tuesday)
  FROM entries AS s
  WHERE t.week='CUR_MON' AND s.week='W_OFF' AND t.name=s.name;

  UPDATE entries AS t
  SET wednesday = COALESCE(t.wednesday, s.wednesday)
  FROM entries AS s
  WHERE t.week='CUR_MON' AND s.week='W_OFF' AND t.name=s.name;

  UPDATE entries AS t
  SET thursday = COALESCE(t.thursday, s.thursday)
  FROM entries AS s
  WHERE t.week='CUR_MON' AND s.week='W_OFF' AND t.name=s.name;

  UPDATE entries AS t
  SET friday = COALESCE(t.friday, s.friday)
  FROM entries AS s
  WHERE t.week='CUR_MON' AND s.week='W_OFF' AND t.name=s.name;

  UPDATE entries AS t
  SET saturday = COALESCE(t.saturday, s.saturday)
  FROM entries AS s
  WHERE t.week='CUR_MON' AND s.week='W_OFF' AND t.name=s.name;

  -- 3) Remove source duplicate rows after merge (only those names present in both weeks)
  DELETE FROM entries
  WHERE week='W_OFF'
  AND name IN (SELECT name FROM entries WHERE week='CUR_MON');

  -- 4) Move non-duplicate rows from W_OFF to CUR_MON
  UPDATE entries SET week='CUR_MON' WHERE week='W_OFF';

  -- 5) Optionally, if future week row is now empty, delete it (safe)
  DELETE FROM weeks WHERE week='W_OFF'
  AND NOT EXISTS (SELECT 1 FROM entries WHERE week='W_OFF');

  COMMIT;

  - If anything looks wrong before COMMIT, type ROLLBACK; instead.

  Post‑fix verification

  - Confirm W_OFF has no entries:
      - sqlite3 data/walkweek.sqlite "SELECT COUNT(*) FROM entries WHERE week='W_OFF';"
  - Confirm totals did not change:
      - sqlite3 data/walkweek.sqlite "SELECT
  COALESCE(SUM(COALESCE(monday,0)+COALESCE(tuesday,0)+COALESCE(wednesday,0)+COALESCE(thursd
  ay,0)+COALESCE(friday,0)+COALESCE(saturday,0)),0) FROM entries;"
  - Confirm all affected names have rows under CUR_MON and that day columns look correct:
      - sqlite3 -newline $'\n' data/walkweek.sqlite "SELECT name,
  monday,tuesday,wednesday,thursday,friday,saturday FROM entries WHERE week='CUR_MON' ORDER
  BY name;"
  - Confirm the “active week” is now CUR_MON and future unfinalized weeks are gone or
  finalized in weeks.

  Reset award-date cache (so awarded_at recomputes)

  - Identify impacted users by name from W_OFF before the move. Get their IDs:
      - sqlite3 data/walkweek.sqlite "SELECT id FROM users WHERE name IN (SELECT name FROM
  entries WHERE week='CUR_MON');"
  - Clear cached award dates for these users only (harmless if table absent):
      - sqlite3 data/walkweek.sqlite "DELETE FROM user_awards_cache WHERE user_id IN
  (SELECT id FROM users WHERE name IN (SELECT name FROM entries WHERE week='CUR_MON'));"
  - This ensures lifetime award earned dates recompute using the corrected week mapping.
  Images/SMS logs are unaffected.

  Final sanity checks

  - Spot check a participant’s award page:
      - In browser: https://example.com/walk/site/user.php?id=<USER_ID>
      - Earned date should read the real calendar day (e.g., Oct 15) now.
  - If needed, re-run the awards API:
      - Run on the server (replace host/path as needed):
        ```bash
        ssh deploy@example-host "cd /var/www/your-app && php -r 'require \"vendor/autoload.php\"; \\App\\Core\\Env::bootstrap(\'.\'); echo json_encode(require \"api/user_awards.php\");'"
        ```
      - Or simpler, refresh the profile page; it fetches `api/user_awards.php`.

  Rollback plan

  - If anything goes sideways, stop and restore the backup:
      - systemctl stop php8.2-fpm (optional)
      - cp data/backup/walkweek_$ts.sqlite data/walkweek.sqlite
      - systemctl start php8.2-fpm (optional)
  - Because we used a single transaction, partial writes won’t occur unless you committed.

  Notes and edge cases

  - If you have multiple future weeks (W_OFF1, W_OFF2…), handle each in turn. Always re-run
  the audit/dry‑run checks between.
  - If any duplicate rows exist and you want an even more conservative rule, keep existing
  CUR_MON values whenever both days are set, which is what the COALESCE-based merge already
  does (target stays if non-null).
  - If your entries table has short day columns (mon..sat) instead of long form, tell me
  — I’ll paste a short-day version of the merge updates. From your server, it’s long-form
  (monday..saturday).
