# Kings Walk Week — Walk together. Win today.

A tiny, joyful, self‑hosted app for running weekly step challenges with your family, friends, or team. It’s SMS‑first (text your steps), privacy‑respecting (SQLite + PHP), and beautiful out of the box.

Start a week. Text your steps. Watch the live leaderboard, cheer with badges, and snapshot winners. Run it on a Raspberry Pi in your kitchen or any PHP host.

— Bring people together around movement — without ads, accounts, or friction.

## Why you’ll love it

- Live leaderboard and charts: See totals, averages, best days, and trends in real time.
- Text-to-log (Twilio): Participants simply text their daily steps — no login.
- Snapshot weeks: Finalize a week to freeze awards and keep a clean historical record.
- Delightful awards: Automatic badges for milestones; AI‑ready with graceful local fallback.
- Private by design: One‑file SQLite database; self‑host on a Pi. Your data, your rules.
- Zero-config deploy: One script backs up data, syncs, and runs migrations.
- Strong tests: PHPUnit coverage across SMS flows, awards, and data.

Optional: add your screenshots here to show the leaderboard and awards.

## Quick Start

Requirements
- PHP 8.2+ with PDO SQLite
- SQLite3 (bundled with PHP)
- Composer (vendor already committed, but recommended)
- (Optional) Twilio account for SMS webhooks

Setup
```bash
cp .env.example .env
# Set at least: ADMIN_USER, ADMIN_PASS, APP_ENV=dev

php api/migrate.php            # create the SQLite DB
php -S localhost:8080 -t public
# Open http://localhost:8080/site/
```

First week
- Use Admin → Weeks to create your first week, or POST to `api/weeks.php` with `action=create&date=YYYY-MM-DD`.

## Features in Detail

- SMS Inbound (Twilio‑ready)
  - POST `/api/sms` (router) accepts bodies like `12345` or `Tue 12345`.
  - Rate‑limited per sender; optional signature verification.
  - Clear responses (“Recorded 12,345 for Mike today.” / helpful errors).

- Public Site
  - `site/index.html`: live leaderboard, awards, day‑by‑day and trajectory charts.
  - `site/lifetime.html`: lifetime totals and participation.

- Admin Console
  - Weeks: create, delete (with force), finalize snapshots.
  - Entries: quick edit, add all actives to week.
  - Users: manage profiles, photos, phones (E.164 helper).
  - AI: approve queue, send, and configure providers.

- Awards & AI
  - Store badges under `site/assets/awards/{user_id}/...` (relative paths only).
  - Settings toggles: `ai.enabled`, `ai.award.enabled`, and provider/model.
  - Graceful fallback to local SVG/WebP when AI isn’t configured.

## Deploy (Raspberry Pi or any PHP host)

Safe, one‑command deploy with backup and migrations:
```bash
./scripts/deploy_to_pi.sh
```
What it does
- Backs up remote `data/` to `backup/` locally (tar or rsync fallback).
- Rsyncs code (excludes local data, .git, etc.).
- Ensures `api/data -> ../data` symlink; fixes permissions for `www-data`.
- Runs `php api/migrate.php` remotely.
- Preps an Nginx snippet for `/api/*` routing and restarts PHP‑FPM.

Pro tip: The snippet is generated; include it in your Nginx server block once.

## Configuration

Environment (.env or .env.local at repo root)
- `APP_ENV=dev|prod` — dev enables friendlier defaults.
- `ADMIN_USER`, `ADMIN_PASS` — required in prod to protect admin.
- `DB_PATH` — optional, defaults to `data/walkweek.sqlite`.
- `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER` — optional; enable SMS sending and signature checks.
- `CSRF_SECRET` — optional; set for deterministic tokens across restarts.

Public settings (`site/config.json`)
- Customize day order, goals, nudges, thresholds, award labels, and milestones.
- Frontend also reads `api/public_settings.php` overrides for milestones and award chips.

## Security & Privacy

- Admin auth must be configured in production (basic auth over HTTPS).
- CSRF protection on admin actions; sessions are short‑lived per request.
- SQLite WAL mode + busy timeouts for resilience on small hardware.
- No trackers. No external accounts for participants. Own your data.

## API Overview

- Router endpoints
  - `POST /api/sms` — inbound steps (Twilio webhook target)
  - `POST /api/sms/status` — delivery status (Twilio)
  - `POST /api/send-sms` — admin‑initiated outbound

- File endpoints
  - `api/weeks.php` — list/create/delete weeks
  - `api/data.php` — current week data (or snapshot)
  - `api/lifetime.php` — lifetime statistics
  - `api/award_generate.php` — admin award generation
  - `api/health.php` — app health + DB check

## Develop & Test

Run migrations and tests
```bash
php api/migrate.php
./vendor/bin/phpunit tests/ --testdox
```

Smoke tests and scripts
- `scripts/test_weeks_api.sh` — quick verify of weeks API (edit BASE if needed).
- `bin/smoke_sms.sh` — local SMS smoke tests with `SMS_SMOKE_BASE_URL=http://localhost`.

## Call to Action

- Run a week with your family this month. Share the leaderboard at dinner.
- Star the repo if you like privacy‑first, feel‑good software.
- Open an issue with your use‑case — I’d love to hear how you use it.

— Kings Walk Week: Walk together. Win today.

- Endpoints
  - `POST api/award_generate.php` (admin + CSRF)
    - Body: JSON `{ user_id, kind, milestone_value, force? }`
    - Response: `{ ok:true, path }` or `{ ok:true, skipped:true, reason }` or `{ ok:false, error }`
    - On success, updates the `ai_awards` row with `image_path` and metadata.
  - `POST api/award_regen_missing.php` (admin + CSRF)
    - Body: JSON `{ kind? }` — optional filter.
    - Response: `{ ok:true, generated, skipped, errors }`.

- Admin UI
  - New “Awards Images” card in `admin/index.php` under AI Settings: generate a single award or regenerate all missing.

- Logging
  - Events logged to `data/logs/ai/award_images.log`:
    - `[timestamp] user={id}:{name} kind={kind} milestone={val} provider={p}|fallback path={path} cost={cost|null} status=ok|skipped|error reason={...}`

- Idempotency
  - If an image for the same user+kind+milestone exists within the last 24 hours and `force` is false, the latest file is reused and returned.


## Author / Contact

Repository maintained by the project owner.
