# App Audit — King Walk Week

Date: 2026-01-11

This audit reviews the King Walk Week app architecture, data flows, security posture, operational behavior, and test coverage. It highlights strengths, risks, and concrete recommendations.

## Summary

Strengths
- Clear, low-dependency PHP + SQLite stack with WAL + busy timeouts (`app/Config/DB.php`).
- Good input handling in SMS pipeline with explicit audit logging, rate limiting, and error responses (`app/Controllers/SmsController.php`).
- Admin endpoints generally protected by Basic Auth and CSRF for state changes (`api/lib/admin_auth.php`, `app/Security/Csrf.php`).
- Strong operational docs and scripted deploys/cron guidance (`README.md`, `AGENTS.md`).

Key risks
- Admin auth is enforced differently between router-backed admin pages and file-based API endpoints; `/admin/*` via controllers can be unprotected when `ADMIN_USER`/`ADMIN_PASS` are unset (`app/Security/AdminAuth.php`).
- Twilio auth tokens are logged in plaintext in `data/logs/sms_bad_sig.log` when signatures fail (`app/Controllers/SmsController.php`).
- Production data appears tracked in-repo (`data/walkweek.sqlite`), which risks accidental exposure or drift between environments.
- Log retention and rotation are uneven; audit tables can grow without scheduled rotation (`scripts/rotate_audit.php` exists but is not enforced by default).

## Architecture & Data Flows

Runtime entry points
- Public router: `public/index.php` + `routes/web.php` for `/api/*` router endpoints and `/admin/*` pages.
- File-based API endpoints: `api/*.php` (legacy but still active in many workflows).
- Static site: `site/index.html`, `site/lifetime.html` with assets under `public/assets/`.

Core flows
- Inbound SMS: `POST /api/sms` routes to `app/Controllers/SmsController::inbound`, validates sender, parses steps, writes entries and audit rows.
- Admin UI: HTML templates under `templates/admin/` rendered by `App\Controllers\AdminController` and related controllers.
- Awards and AI: `api/award_generate.php` (admin + CSRF) and optional AI/award integrations.
- Reminders: cron-driven `bin/run_reminders.php` with opt-in flags in `users` and `reminders_log`.

Data storage
- Primary DB: SQLite at `data/walkweek.sqlite` (default), configured in `app/Config/DB.php`.
- Schema management: `api/migrate.php` (idempotent, runtime) plus Phinx migrations under `database/migrations/` (scripted via deploy).
- Audit tables: `sms_audit`, `sms_outbound_audit`, `message_status` (delivery receipts), and `reminders_log`.

## Security & Privacy Review

Authentication and authorization
- Router-backed admin pages call `App\Security\AdminAuth::require()`.
  - If `ADMIN_USER`/`ADMIN_PASS` are unset, AdminAuth allows access unconditionally (no APP_ENV gating) (`app/Security/AdminAuth.php`).
- File-based endpoints use `require_admin()` which blocks access in non-dev environments when creds are missing (`api/lib/admin_auth.php`).
- Result: inconsistent protection between `/admin/*` pages and `/api/*.php` endpoints.

CSRF
- CSRF tokens are enforced on most state-changing admin endpoints (`app/Security/Csrf.php`).
- Some admin endpoints rely on Basic Auth only (e.g., exports) by design; this should be documented as a deliberate tradeoff.

Twilio verification
- Signature verification is robust (HMAC, canonical URL, trusted IP bypass, fallback token support) (`app/Security/TwilioSignature.php`).
- Failure logging currently includes raw auth tokens in `sms_bad_sig.log` (see Findings below).

SMS admin prefix
- Admin actions by SMS are gated by `sms.admin_prefix_enabled` and a stored password in the DB (`settings` table). The password is stored in plaintext (appropriate for low-risk local use, but a potential privacy issue if DB is leaked).

File uploads
- User photo upload verifies MIME type, re-encodes via GD, limits size, and writes to a scoped path (`api/admin_upload_photo.php`). This is solid for a PHP app.

## Reliability & Operations

Resilience
- SQLite uses WAL mode, busy timeouts, and cautious retries for write contention (`app/Config/DB.php`, `app/Controllers/SmsController.php`).
- SMS status callbacks tolerate DB errors and always return 200 to prevent retry storms (`app/Controllers/SmsController.php`).

Deploy & cron
- Deploy scripts handle backups, rsync, migrations, and service reloads (`scripts/deploy_to_pi.sh`).
- Reminders cron is documented in `README.md` and `AGENTS.md`, but is not installed automatically.

Observability
- Logs exist for SMS failures, award generation, and AI operations (`data/logs/`).
- Rotation is manual; `scripts/rotate_audit.php` exists but needs scheduling if used in production.

## Test Coverage

Existing tests
- SMS parsing, Twilio signature, admin SMS flows, and DB helpers have PHPUnit tests (`tests/*`).

Gaps
- File-based API endpoints under `api/*.php` have minimal integration coverage.
- Admin auth differences between router/controller paths and file-based paths are not tested.
- No automated checks validate deploy scripts or cron installation.

## Findings (Prioritized)

High
1) Admin auth inconsistency: `/admin/*` pages rendered via `App\Security\AdminAuth` allow access when `ADMIN_USER`/`ADMIN_PASS` are unset, regardless of `APP_ENV`, while file-based endpoints block in non-dev (`app/Security/AdminAuth.php`, `api/lib/admin_auth.php`).
2) Twilio auth token leakage in logs: signature failure logs include `raw_tokens` (Twilio auth tokens) in `data/logs/sms_bad_sig.log`, creating a credential exposure risk if logs are accessed (`app/Controllers/SmsController.php`).

Medium
3) Production data appears tracked in-repo: `data/walkweek.sqlite` is present and larger than the sample DB, risking data exposure or drift if committed/pushed.
4) Audit/operational logs lack rotation by default; `sms_audit` and `sms_outbound_audit` can grow without scheduled cleanup (`scripts/rotate_audit.php` exists but not enforced).

Low
5) Mixed migration strategies (`api/migrate.php` and Phinx) increase the chance of drift between environments if scripts are not run consistently.
6) Session settings for admin endpoints use PHP defaults; cookie security flags (Secure/SameSite) are not explicitly set for production.

## Recommendations (Actionable)

1) Unify admin auth behavior
- Make `App\Security\AdminAuth` require credentials in non-dev envs (mirror `api/lib/admin_auth.php` behavior).
- Alternatively, route `/admin/*` through the same `require_admin()` helper.

2) Remove secrets from signature-failure logs
- Stop logging `raw_tokens`; log token hashes only. Retain request context without credential exposure.

3) Separate data from repo
- Ensure `data/walkweek.sqlite` is not committed for production installs; use a sample DB or `.gitignore` for real data.

4) Operationalize log/audit rotation
- Schedule `scripts/rotate_audit.php` (or equivalent) and document retention policy in `docs/`.

5) Clarify migration source of truth
- Decide whether Phinx or `api/migrate.php` is authoritative and document the workflow to avoid drift.

6) Harden session cookie settings
- In production, set `session.cookie_secure`, `session.cookie_httponly`, and `session.cookie_samesite` explicitly (e.g., via server config or bootstrap).

## Quick Reference (Key Files)

- Routing: `public/index.php`, `routes/web.php`
- Admin auth: `app/Security/AdminAuth.php`, `api/lib/admin_auth.php`
- CSRF: `app/Security/Csrf.php`
- SMS inbound: `app/Controllers/SmsController.php`
- DB config: `app/Config/DB.php`
- Migrations: `api/migrate.php`, `database/migrations/`
- Deploy: `scripts/deploy_to_pi.sh`, `scripts/deploy_to_local.sh`
- Reminders: `bin/run_reminders.php`
