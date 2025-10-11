Here are concise Cline prompts. Run in order.

Prompt 1 — Collapse SMS endpoints into controller

Goal: Route api/sms.php, api/send_sms.php, api/sms_status.php through App\Http\Controllers\SmsController.

Audit mode:
	•	List these files and any other SMS endpoints.
	•	Find duplicated logic: Twilio signature checks, TwiML vs JSON, DB writes, audits.
	•	Show a call graph for inbound, outbound, and status webhooks.

Act mode:
	•	Create App/Http/Controllers/SmsController.php with methods: inbound(), status(), send().
	•	Replace endpoint scripts with thin shims that include bootstrap and call controller, or update router to map routes.
	•	Keep behavior identical. Add unit tests for each method.

Prompt 2 — Unify Twilio signature verification

Goal: One verifier, configurable skip in non-prod.

Audit mode:
	•	Locate all code that reads X-Twilio-Signature.
	•	Note env flags used. Identify any implicit “skip if header missing” paths.

Act mode:
	•	Add App/Security/TwilioSignature.php with verify(request, url, token): bool.
	•	Add TWILIO_SKIP_SIG=0|1 and APP_ENV=prod|dev|test. If APP_ENV!=prod and TWILIO_SKIP_SIG=1, bypass.
	•	Replace all ad-hoc checks with this class. Add tests for valid, invalid, and bypass cases.

Prompt 3 — Standardize DB access

Goal: One PDO source.

Audit mode:
	•	Find direct pdo() calls and any raw PDO creations.
	•	List all DB helpers.

Act mode:
	•	Create or use App\Config/DB::pdo() as the only entry point.
	•	Replace direct calls. Ensure consistent error mode, transactions, and timeouts.
	•	Add a smoke test that opens and queries the DB via DB::pdo().

Prompt 4 — Make rate limits configurable

Goal: Externalize per-number inbound limit and AI reply limit.

Audit mode:
	•	Find hardcoded throttles for inbound SMS and AI auto-reply.

Act mode:
	•	Add app_settings keys: sms.inbound_rate_window_sec, sms.ai_rate_window_sec.
	•	Read at runtime in controller.
	•	Add tests that set small windows and assert throttling.

Prompt 5 — Extract TwiML vs JSON responder

Goal: One response helper.

Audit mode:
	•	Find places that branch on “is Twilio request” and build TwiML or JSON.

Act mode:
	•	Create App\Http/Responders\SmsResponder with ok(message, format), error(message, format).
	•	Auto-detect format from request origin or explicit query flag.
	•	Replace duplicated response code.

Prompt 6 — Dedupe outbound SMS paths

Goal: Use one service for sending and auditing.

Audit mode:
	•	Find all Twilio REST sends and outbound audit writes.

Act mode:
	•	Implement App/Services/Outbound::sendSMS(toE164, body): Sid.
	•	Make SmsController::send() call the service.
	•	Delete or reduce old endpoint to a thin wrapper. Add tests that mock Twilio and assert audit rows.

Prompt 7 — Collapse status webhooks

Goal: One status handler.

Audit mode:
	•	List all status webhook endpoints and their storage tables.

Act mode:
	•	Implement SmsController::status() with signature verify and insert/update into message_status.
	•	Update Twilio console to point to this endpoint.
	•	Remove duplicate libs or scripts. Add tests for delivered, failed, undelivered.

Prompt 8 — Move schema creation to migrations only

Goal: No runtime CREATE TABLE IF NOT EXISTS.

Audit mode:
	•	Grep for CREATE TABLE in non-migration code.

Act mode:
	•	Add or update migrations for sms_audit, sms_outbound_audit, message_status.
	•	Remove runtime DDL. Add migration tests or a migrate-then-start script.

Prompt 9 — Add pruning as a scheduled job

Goal: Rotate audit tables.

Audit mode:
	•	Locate any ad-hoc prune scripts and retention constants.

Act mode:
	•	Create scripts/rotate_audit.php or a CLI command that deletes rows older than sms.audit_retention_days in settings.
	•	Add a systemd timer or cron entry example to run daily.
	•	Log deletes with counts.

Prompt 10 — Remove dev data and hardcoded award paths

Goal: Clean dev artifacts that pollute SMS features and DB.

Audit mode:
	•	Find migrations or seeders that insert dev awards or hardcoded paths.

Act mode:
	•	Guard seeds behind APP_ENV=dev or remove them.
	•	Ensure paths are relative and derived from config.
	•	Run migration reset in dev. Verify production has no dev rows.

Prompt 11 — Admin settings hygiene

Goal: Ensure only used flags exist and are wired.

Audit mode:
	•	Enumerate settings.ai.enabled, settings.ai_autosend, and any per-type toggles.
	•	Find unused keys.

Act mode:
	•	Remove unused keys from code and UI.
	•	Add a single settings read path.
	•	Add tests: when disabled, AI code path is not called; when enabled with autosend, it calls Twilio once.

Prompt 12 — Tests and smoke scripts

Goal: Lock behavior after cleanup.

Audit mode:
	•	List existing tests. Identify gaps for inbound parse, rate limits, TwiML output, outbound send, status callback.

Act mode:
	•	Add PHPUnit tests:
	•	Inbound happy path: audit, parse, write steps, TwiML confirmation.
	•	Inbound throttled.
	•	Outbound send: Twilio mocked, audit row written.
	•	Status webhook: delivered and failed.
	•	Signature verify: valid, invalid, bypass.
	•	Add bin/smoke_sms.sh to POST sample payloads to local server and print responses.
