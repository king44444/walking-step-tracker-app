# King Walk Week — Weekly Step Tracker

A lightweight web + SMS app for tracking weekly step counts. Users report daily step totals via SMS (Twilio) or the web UI; the server records entries per week and provides simple reporting and lifetime stats.

This repository contains the API (PHP + SQLite), a small single-page frontend, and maintenance/deploy scripts used to run the app on a Raspberry Pi (or other web host).

---

## Quick start / TL;DR

1. Create an environment file for the API: `api/.env.local` (see Configuration below).
2. Run migrations to create the SQLite DB and schema:
```bash
php api/migrate.php
```
3. Serve the project for local testing:
```bash
php -S localhost:8000 -t .
# then open http://localhost:8000/site/
```
4. Deploy to the Pi with:
```bash
./deploy_to_pi.sh
```

---

## Repository layout (important files)

- `/api` — PHP backend endpoints and helpers
  - `api/sms.php` — Twilio SMS webhook (see API section)
  - `api/sms_status.php`, `api/weeks.php`, `api/data.php`, `api/lifetime.php` — supporting endpoints
  - `api/migrate.php` — DB migration / schema creation script
  - `api/db.php` — SQLite connection (data/walkweek.sqlite)
  - `api/README.md` — Twilio-related docs and signature debug playbook
  - `api/lib/*` — helper libraries
- `/site` — frontend static site (HTML, CSS, JS)
  - `site/config.json` — runtime configuration for goals, thresholds, labels
  - `site/app/*` — main client code
- `/scripts` — maintenance and signing helpers
  - `scripts/backup_db.sh` — DB backup/rotation
  - `scripts/rotate_audit.php` — prune old audit rows (cron)
  - `scripts/twilio_sign.py` + `scripts/curl_signed.sh` — helper to send signed test callbacks
- `deploy_to_pi.sh` — rsync-based deploy script (syncs files, sets permissions, runs migration)
- `/data` — runtime data (SQLite DB: `data/walkweek.sqlite`)
- `index.html` — redirects to `/site/`

---

## Requirements

- PHP 8+ (for running the API & migrations; recommended to run under PHP-FPM on the Pi)
- SQLite3 (bundled with PHP PDO SQLite)
- Python 3 (for `scripts/twilio_sign.py` used in signature tests)
- curl, rsync, ssh (for deploy script)
- (Optional) Twilio account for real SMS webhook testing

---

## Configuration

1. api/.env.local (not committed)
- Create `api/.env.local` and set environment variables used by the API (example keys):
  - WALK_TZ (example: `America/Denver`)
  - TWILIO_AUTH_TOKEN (Twilio auth token — leave empty to disable signature verification)
- The API code reads these via `api/lib/env.php` / FPM environment when deployed.

2. site/config.json (committed)
- Controls display and gameplay rules (DAILY_GOAL_10K, NUDGES, LEVELS, LIFETIME_STEP_MILESTONES, etc.)
- See `site/config.json` for current settings; change carefully to affect frontend behavior.

3. Database location
- SQLite DB path is `data/walkweek.sqlite` (created by `api/db.php`).
- DB connection uses PRAGMA journal_mode=WAL and foreign_keys=ON.

---

## Database schema (summary)

Main tables and notable items (created by `api/migrate.php`):

- `weeks` (week TEXT PK, label, finalized, created_at, finalized_at)
- `entries` (id, week, name, monday..saturday, sex, age, tag, updated_at)
  - Additional per-day first-report timestamp columns: `mon_reported_at`, `tue_reported_at`, ... (integer epoch seconds)
  - Triggers created to set per-day reported_at only on the first non-null positive submission
  - Unique index on (week, name)
- `snapshots` (week PK, json, created_at)
- `users` (id, name, sex, age, tag, phone_e164, ...) — backfilled from entries if missing
- `sms_audit` (incoming SMS audit log with parsed status)
- View: `lifetime_stats` — aggregated lifetime totals per active user

---

## API: endpoints & behavior

See `api/README.md` for Twilio-specific and signature debugging notes.

- `api/sms.php` — Twilio webhook to record steps
  - Accepts body formats:
    - `12345`
    - `Tue 12345` (day override, 3–9 alpha chars)
  - Validation:
    - Exactly one numeric group required
    - Steps must be integer in [0, 200000]
  - Rate limiting: 60s per phone number (based on recent successful `sms_audit` row)
  - If `TWILIO_AUTH_TOKEN` is set, verifies Twilio signature; invalid signature => 403
  - Logs every request to `sms_audit` with a `status` value (ok, bad_signature, rate_limited, unknown_number, invalid_steps, etc.)
  - Typical responses are plain text (e.g., `Recorded 12,345 for Mike on today.` or `Number not recognized. Ask admin to enroll your phone.`)

- `api/data.php` — returns the currently active week data JSON (or snapshot if week finalized)
  - Includes `rows` (entries), `firstReports` array, and `lifetimeStart` totals (steps before this week)
  - `todayIdx` is computed (Mon..Sat => 0..5, Sunday => -1)

- `api/weeks.php` — list of weeks (used by frontend)
- `api/lifetime.php` — lifetime stats view

- Admin UI:
  - `admin/phones.php` — enroll or normalize phone numbers (save E.164 in `users.phone_e164`)
  - `admin/admin.php` — admin tasks (requires basic auth in deploy environment)

---

## Running & testing locally

- Create DB and schema:
```bash
php api/migrate.php
```

- Serve with PHP built-in server for quick testing (not suitable for production):
```bash
php -S localhost:8000 -t .
# then open http://localhost:8000/site/
```

- Test SMS webhook (simple, unsigned):
```bash
curl -i http://localhost:8000/api/sms.php \
  --data-urlencode "From=+18015551234" \
  --data-urlencode "Body=12345"
```

- Test signed webhook flow (deployment signature helper):
1. On deploy host (or locally with correct env), set:
```bash
export URL="https://<host>/api/sms_status.php"
export AUTH="<TWILIO_AUTH_TOKEN>"
./scripts/curl_signed.sh
```
2. `scripts/twilio_sign.py` computes the Twilio-like signature for the test payload; `curl_signed.sh` POSTs it with the `X-Twilio-Signature` header.

Signature debug steps are documented in `api/README.md` (diag endpoint `_sig_diag.php` and common mismatch causes).

---

## Deployment

The included `deploy_to_pi.sh` performs an rsync to the Pi host configured inside the script, fixes permissions for the web user, and runs `php api/migrate.php` on the remote.

Example (run from project root on the dev machine):
```bash
./deploy_to_pi.sh
```

Notes:
- The script excludes `.git`, `.DS_Store`, `site/_bak`, and the local `data/walkweek.sqlite` by default.
- After deploy, migrations run on the Pi to ensure schema and triggers are in place.
- Ensure SSH/rsync access to the Pi (`PI_HOST`, `PI_USER` inside the script).

---

## Maintenance & cron jobs

- Backups: `scripts/backup_db.sh` — zips `data/walkweek.sqlite` into `backup/` and keeps the last 14 zips. Example cron:
```cron
@daily /path/to/project/scripts/backup_db.sh
```

- Audit rotation: `scripts/rotate_audit.php` — deletes `sms_audit` rows older than 90 days. Example cron:
```cron
@daily php /path/to/project/scripts/rotate_audit.php
```

- Signature test helper:
  - `scripts/twilio_sign.py` and `scripts/curl_signed.sh` — used during signature verification debugging.

---

## Security & privacy notes

- Do NOT commit `api/.env.local` or any secrets.
- Keep `TWILIO_AUTH_TOKEN` secret. If set, Twilio signature verification is enforced.
- Phone numbers are stored in `users.phone_e164` when enrolled. Use E.164 format when possible.
- Admin UI for enrolling/normalizing phone numbers is available at `admin/phones.php` (protected by deployment-level auth).

---

## Contributing & workflow

- Make changes on a feature branch, test locally, then push and deploy via `deploy_to_pi.sh`.
- Commit message suggested for README addition:
```
git commit -m "Add README.md — project overview, setup, and deployment"
```
- The repository remote is `origin: https://github.com/king44444/walking-step-tracker-app.git`.

---

## Notes / TODOs

- Consider adding automated tests for parsing/validation logic (sms parsing).
- Add explicit license file if redistribution is intended.
- Add CI pipeline to run `php -l` and simple linting on commits.

---

## Author / Contact

Repository maintained by the project owner.
