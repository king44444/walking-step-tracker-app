# AI Audit — King Walk Week

Date: 2025-10-08

This document inventories all AI-related code paths, data stores, settings, and operational behaviors in the project, calls out risks and gaps, and proposes concrete improvements.

## Summary

The AI subsystem generates short SMS replies to participant messages and supports admin-reviewed outbound nudges/announcements. It uses OpenRouter for LLM completions and Twilio for outbound SMS. Controls include an enable toggle, a model selector, and an optional auto-send mode; an approval queue is provided when auto-send is off.

Core themes:
- The live SMS path integrates AI reply generation but is tolerant to AI failures (core logging still succeeds).
- Admin tooling supports queue review and bulk send, backed by the `ai_messages` table.
- There are two parallel admin API stacks: legacy `api/*.php` and an unused MVC `app/Controllers/*`. The `api/*.php` stack is the one wired into the UI.
- Logging destinations for AI are inconsistent (two files/paths).

## Components & Files

- OpenRouter integration
  - `api/lib/openrouter.php`: thin client; `or_chat_complete($model, $messages, $temp=0.5, $max_tokens=160)`.
  - `api/lib/config.php`: reads `OPENROUTER_API_KEY` (required), resolves `openrouter_model` from DB `settings` or env `OPENROUTER_MODEL` (default `anthropic/claude-3.5-sonnet`).
  - `api/lib/ai_sms.php`: prompt building and reply generation (`generate_ai_sms_reply`), cleans up output, length clamps to 240 chars, logs generation.

- Inbound SMS AI hook
  - `api/sms.php`: main Twilio webhook and local/integration tester.
    - After recording steps, conditionally generates an AI reply if `ai_enabled` is set and a 120s per-user cooldown passes (see “Gating & rate limits”).
    - Inserts a row into `ai_messages`, updates `user_stats.last_ai_at`, and auto-sends via Twilio when `ai_autosend=1`.

- Admin AI APIs (active, used by UI)
  - `api/ai_list.php`: list unsent/sent queue items. Admin-auth required.
  - `api/ai_approve_message.php`: approve/unapprove one message (stores `approved_by`).
  - `api/ai_delete_message.php`: delete one unsent message.
  - `api/ai_delete_all.php`: purge all unsent messages (optionally for a given week).
  - `api/ai_send_approved.php`: send approved + unsent items for a week.
  - `api/ai_rules.php` (legacy rules engine for nudges/recaps; still callable from legacy page).
- `api/ai_log.php`: reads the real LLM log `data/logs/ai/ai_generation.log` (falls back to `data/logs/ai_stub.log` if missing).

- Admin UI (active)
  - `admin/ai.php`: AI Console (toggle, model, auto-send; queue listing, approve/delete, send; log preview).
  - `admin/index.php` Weeks panel: exposes AI toggle/model/autosend summary.
- `admin/ai_legacy.php`: legacy page with rules runner and approval UX; removed.

- MVC-based controllers (not wired into current UI)
  - `app/Controllers/Api/AiController.php`, `app/Repositories/AiMessageRepo.php`, `app/Services/AIService.php`, tests in `tests/*`.
  - These expect an `ai_messages` schema with an `approved` boolean (doesn’t match the live `approved_by` pattern). Consider deprecating or aligning.

- Outbound SMS
  - `api/lib/outbound.php`: Twilio REST call (`TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM`).

## Data Model

Database objects relevant to AI (created by `api/migrate.php`):

- `settings(key TEXT PRIMARY KEY, value TEXT)`
  - Keys used: `ai_enabled` ('0'|'1'), `ai_autosend` ('0'|'1'), `openrouter_model` (string).

- `ai_messages` (queue)
  - Columns: `id, type, scope_key, user_id, week, content, model, prompt_hash, approved_by, created_at, sent_at, provider, raw_json, cost_usd`.
  - Indexes: by `week`, `user_id`, and `(approved_by, sent_at)` for sendable queries.

- `user_stats` (auto-created in `sms.php` if missing)
  - Columns: `user_id PRIMARY KEY, last_ai_at TEXT` — used for per-user AI cooldown.

- `app_settings` (legacy), `ai_awards`, `user_ai_profile` — used by rules/awards UIs; not involved in LLM SMS generation path.

## Settings & Environment

- Required
  - `OPENROUTER_API_KEY` — Bearer token for OpenRouter.
  - `ADMIN_USER`, `ADMIN_PASS` — for admin endpoints (Basic Auth). If unset, admin is effectively open (dev-friendly) — see `api/lib/admin_auth.php`.

- Optional / Recommended
  - `OPENROUTER_MODEL` — default model name (overridden by DB `openrouter_model`).
  - `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM` — for outbound SMS sending.

- Admin-set via API
  - `ai_enabled`, `ai_autosend`, `openrouter_model` (via `api/get_setting.php` / `api/set_setting.php`).

## Data Flows

1) Inbound SMS with optional AI reply
- `POST /api/sms.php` (from Twilio or internal/test)
  - Parses message; records steps into `entries`.
  - If `get_setting('ai_enabled') === '1'` and user’s `last_ai_at` is older than 120s:
    - Builds prompt in `api/lib/ai_sms.php`.
    - Calls OpenRouter `chat/completions`.
    - Sanitizes and clamps reply to <= 240 chars.
    - Inserts row into `ai_messages` (type 'sms').
    - Updates `user_stats.last_ai_at`.
    - If `ai_autosend='1'`, sets `approved_by='auto'` and attempts to send via Twilio immediately; marks `sent_at` on success.
  - Returns JSON or TwiML response confirming receipt of steps; AI generation errors are swallowed and logged.

2) Admin queue review and sending
- `GET /api/ai_list.php?status=unsent|sent` → queue JSON.
- `POST /api/ai_approve_message.php` (`id`, `approved` 0/1) → marks `approved_by`.
- `POST /api/ai_delete_message.php` (`id`) → deletes if `sent_at IS NULL`.
- `POST /api/ai_delete_all.php` (`week?`) → bulk purge unsent.
- `POST /api/ai_send_approved.php` (`week?`) → sends approved + unsent, updates `sent_at`.

3) Rules-based AI (legacy)
- `POST /api/ai_rules.php` — populates message content based on standings/missing data/top3/etc.; used by `admin/ai_legacy.php`.

## Gating, Rate Limits & Cost Controls

- Gating
  - AI is disabled by default: `ai_enabled` seeded to '0' in `migrate.php`.
  - Live webhook checks `ai_enabled` per request.

- Per-user AI cooldown
  - `user_stats.last_ai_at` must be >= 120 seconds ago to generate another AI reply.
  - Fallback: `user_stats` table is created on demand in `sms.php`.

- Model/token limits
  - OpenRouter call sets `temperature=0.4`, `max_tokens=120` (app-level limit; not cost-aware).
  - `ai_messages.cost_usd` is present but not computed; usage-based pricing not tracked.

## Logging & Observability

- Generation log (LLM path): `api/lib/ai_sms.php`
  - Writes to `data/logs/ai/ai_generation.log` with timestamp, model, user, raw incoming, and generated text. (PII present — see Risks.)

- Stub log (simulated path): `api/lib/ai_stub.php` (kept)
  - Writes to `data/logs/ai_stub.log`; used as a fallback for AI log viewer.

- Admin log viewer: `api/ai_log.php`
  - Reads `data/logs/ai_stub.log` only. Does NOT read the LLM path’s `ai_generation.log`. The Admin UI may show “No logs” even when the LLM path is active.

## Security & Privacy

- Admin protection
  - Admin endpoints require Basic Auth via `api/lib/admin_auth.php` (`ADMIN_USER`/`ADMIN_PASS`). If both are empty, access is allowed (dev mode). Ensure these are set in production.

- CSRF
  - The new admin UI pages call `api/ai_*` endpoints without CSRF tokens (Basic Auth only). Consider adding CSRF validation for defense-in-depth.

- Secrets
  - `OPENROUTER_API_KEY`, Twilio creds are read from FPM env / `.env`. Stored nowhere in DB.

- PII in logs
  - `ai_generation.log` includes raw incoming user SMS content and names. Consider redaction, rotation, and/or opt-out flags.

## Error Handling & Resilience

- OpenRouter failures
  - Exceptions in `or_chat_complete` propagate to `generate_ai_sms_reply`, then are caught in `api/sms.php`; AI is skipped, and core SMS flow returns a normal confirmation.

- Twilio send failures (auto-send or bulk-send)
  - Errors are caught; messages remain unsent in the queue for manual retry.

- DB concurrency
  - SMS audit insertions handle SQLITE_BUSY with retries. AI queue writes are simple single-row inserts/updates inside the webhook request.

## Duplications & Inconsistencies

- Admin stacks
  - Two code paths exist: legacy `api/*.php` and MVC `app/Controllers/*`. The UI uses the `api/*.php` endpoints. Repository classes assume an `approved` boolean not present in the live schema. Consider deprecating the unused MVC AI stack or aligning schema/handlers.

- Logging mismatch
- LLM path logs to `data/logs/ai/ai_generation.log`; admin log viewer already reads this (falls back to stub).

- Env var naming
  - Outbound uses `TWILIO_FROM`, while some legacy code references `TWILIO_FROM_NUMBER` in comments. Standardize to `TWILIO_FROM`.

## Test & Tooling

- `scripts/test_ai_flow.sh` — toggles `ai_enabled`, sends test SMS (`/api/sms.php`), fetches AI log. Requires Basic Auth and a `FROM=+1...` number.

- PHPUnit tests exist for the MVC layer (`tests/AIServiceTest.php`, `tests/AiMessageRepoTest.php`), but the UI uses the `api/*.php` layer. Tests do not validate OpenRouter integration or `api/*.php` endpoints.

## Recommendations (Prioritized)

1) Unify logging and make it privacy-aware
   - Decide on a single AI log path (e.g., `data/logs/ai/ai_generation.log`) and update `api/ai_log.php` to read it.
   - Redact/shorten PII in logs; add rotation policy (e.g., daily rotate, keep N days).

2) Harden admin endpoints
   - Add CSRF validation to `api/ai_*` endpoints similar to the `app` CSRF utility.
   - Ensure `ADMIN_USER`/`ADMIN_PASS` are required in production (remove dev auto-open behavior or gate by `APP_ENV`).

3) Consolidate admin API surface
   - Remove/archivize unused MVC AI controllers or refactor to call the `api/*.php` endpoints to avoid schema drift.

4) Cost & usage tracking
   - Capture `usage` from OpenRouter responses and compute `cost_usd` per message (store in `ai_messages`).
   - Add a monthly budget guardrail or per-user daily cap to avoid accidental overruns.

5) Operator safeguards
   - Make `ai_autosend` default to '0' on new installs and add a banner when ON.
   - Add an “Est. monthly cost” panel based on recent volume.

6) Observability
   - Add a simple `/api/ai_health.php` that verifies model/key presence and performs a short dry-run (disabled in production by default).

7) Documentation
   - Document required env keys for outbound Twilio (`TWILIO_FROM`) and OpenRouter usage. Add examples to README.

## Quick Reference (Endpoints)

- Admin settings: `GET /api/get_setting.php?key=...`, `POST /api/set_setting.php` (Basic Auth)
- AI queue: `GET /api/ai_list.php?status=unsent|sent`
- Approve/unapprove: `POST /api/ai_approve_message.php` (`id`,`approved` 0|1)
- Delete one/all: `POST /api/ai_delete_message.php`, `POST /api/ai_delete_all.php` (`week?`)
- Send approved: `POST /api/ai_send_approved.php` (`week?`)
- Rules (legacy): `POST /api/ai_rules.php` (admin)
- AI log (stub): `GET /api/ai_log.php`
- Inbound SMS: `POST /api/sms.php`

## Quick Reference (Config)

- Env: `OPENROUTER_API_KEY`, `OPENROUTER_MODEL`, `ADMIN_USER`, `ADMIN_PASS`, `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM`
- DB settings: `ai_enabled`, `ai_autosend`, `openrouter_model`
