Purpose
- Capture repo conventions, deployment facts, and “gotchas” for future agents/humans.
- Scope: entire repository.

Pathing Rules
- Public site pages under `site/` must not assume domain-root. Use relative paths.
- Site asset policy (current):
  - Load the full, battle‑tested UI from `../public/assets/` in `site/index.html` and `site/lifetime.html`:
    - CSS: `../public/assets/css/app.css`
    - JS module: `../public/assets/js/app/main.js`
- Admin asset policy:
  - Admin pages live under `/admin/`. Use `../site/assets/` for shared images (e.g., fallback photo).
  - A single PHP var is set at the top of admin pages when needed: `$SITE_ASSETS = '../site/assets';`
  - Example: `$thumb = $u['photo_path'] ? ('/'.$u['photo_path']) : ($SITE_ASSETS . '/admin/no-photo.svg');`
- API pathing from admin: use `../api/...` (no domain-root assumptions).

Weeks Normalization
- All weeks normalized to strict ISO `YYYY-MM-DD` and exposed as `starts_on`.
- Added migration `database/migrations/20251006_weeks_normalize.php`.
- Repair script `scripts/repair_weeks.php` pads dates, dedupes duplicate week rows (e.g., `2025-10-5` vs `2025-10-05`), remaps `entries.week` to the canonical ISO date, and re‑indexes.

API Contracts (hardened)
- `api/weeks.php`
  - GET: `{ ok: true, weeks: [{ starts_on, label, finalized }] }` sorted desc, de‑duped.
  - POST `action=create|delete` with `date`: validates/normalizes dates; `delete` supports `force=1` to cascade delete entries in a transaction. Never 500s the UI; logs server details to `api/logs/app.log`.
- `api/data.php`
  - Accepts `week` as `YYYY-M-D` or `YYYY-MM-DD`; normalizes; looks up snapshot if finalized or live rows if open.
  - Returns `{ ok: true, week, label, finalized, source: 'snapshot'|'live', todayIdx, rows, firstReports, lifetimeStart }`.
  - On exceptions: `{ ok:false, error:'server_error' }` and logs under `api/logs/app.log`.

Frontend (site)
- Week selector auto‑picks the most recent week that actually has data.
- Public UI is served from `public/assets/js/app/` and `public/assets/css/` (do not re‑implement partial site‑local JS unless necessary).
- Do not add admin controls (create/delete week) to public pages.

Deployment
- Script: `./scripts/deploy_to_pi.sh` (rsyncs files to Pi; ensures `api/data -> ../data` symlink; restarts php‑fpm; prints Weeks JSON).
- Server base path: `http://<pi>/dev/html/walk`.
- Data dir: `data/walkweek.sqlite` (kept server‑side; excluded from rsync).

Checks / Tools
- Asset check (live):
  - CSS: `curl -fsS "${BASE:-https://mikebking.com/dev/html/walk}/public/assets/css/app.css"`
  - JS:  `curl -fsS "${BASE:-https://mikebking.com/dev/html/walk}/public/assets/js/app/main.js"`
- Weeks API smoke: `scripts/test_weeks_api.sh` (creates, dedup checks, delete w/ and w/o force).
- Data repair: `php scripts/repair_weeks.php` (idempotent, safe).

Conventions
- Relative pathing only (no `/assets/...` or `/site/...` from pages under `site/` or `admin/`).
- Admin → `../site/assets/...` for images; Admin → `../api/...` for API.
- Site → load CSS/JS from `../public/assets/...` (source of truth for UI code).

Known Notes
- Tailwind CDN is used; console warns in production. This is safe but noisy; consider a Tailwind build via PostCSS in a future pass if desired.
- `db_diag.php` currently 404s in deploy diagnostics; non‑blocking.

Quick Tasks for Future Agents
- Fix asset path regressions: follow the Pathing Rules above.
- Add weeks or entries features: follow Weeks Normalization and API Contracts.
- Before changing UI code under `site/`, prefer updating `public/assets/js/app/` once and consume it from `site/` via relative path.

