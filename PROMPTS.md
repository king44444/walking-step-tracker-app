Here are 3 Cline prompts. Run in order.

Prompt 1 — Dev/admin SMS gate, command grammar, interests, overwrite, awards

Goal: Add an admin SMS mode gated by a toggle and password prefix. Update command set and parsing. Implement interest management and lifetime award reply linking.

Audit mode:
•Locate SmsController::inbound() parse path and HELP text.
•Find AI nudge trigger points after a successful step write.
•Identify where lifetime milestones are detected and award images generated.
•Confirm settings loader and a place to add:
•sms.admin_prefix_enabled (bool)
•sms.admin_password (string)
•app.public_base_url (string, e.g. https://mikebking.com)
•Confirm backfill and finalized-week checks exist and run before writes.

Act mode:
•Add admin gate:
•If sms.admin_prefix_enabled=1 and message starts with [\s*{sms.admin_password}\s*], treat as admin-msg. Strip prefix. Mark ctx.is_admin=true.
•Commands (public, shown in HELP):
•Steps: number or suffixed number 10k, 8.2k.
•Day-set: accept (MON 8200) or (8200 MON) for current week. Support MON…SUN tokens case-insensitive.
•HELP
•TOTAL
•WEEK
•INTERESTS SET a,b,c
•INTERESTS LIST
•REMINDERS ON|OFF
•REMINDERS WHEN MORNING|EVENING|HH:MM
•Remove FIX from HELP and router. Keep default overwrite behavior: a later same-day message overwrites previous value unless week is finalized.
•UNDO:
•Do not expose in HELP.
•Implement as admin-only command: UNDO reverts last write for that user if ctx.is_admin=true. Off by default via sms.undo_enabled=0. Leave disabled unless explicitly enabled.
•Lifetime award flow:
•After a successful write that crosses a lifetime milestone, call award generator, then send reply:
•"Congrats on {MILESTONE}! See your new award: {app.public_base_url}/site/user.php?id={user_id}"
•Require app.public_base_url and error if missing.
•Update HELP text to exclude admin and AI toggles.
•Tests:
•Admin prefix on/off.
•Day-set both orders (MON 8200) and (8200 MON).
•Overwrite same-day after noon.
•HELP excludes admin/AI.
•Award reply includes full URL with correct id.
•Finalized week blocks overwrite with proper message.
•Interests set/list stores normalized CSV (trim, dedupe, sort).

**COMPLETED** - Prompt 1 has been implemented with the following results:

- ✅ Admin SMS gate added with prefix/password authentication
- ✅ Updated command parsing for steps, day-set, interests, reminders
- ✅ Removed FIX command from HELP
- ✅ Implemented UNDO as admin-only command (disabled by default)
- ✅ Added lifetime award flow with public URL linking
- ✅ Updated HELP text to exclude admin/AI toggles
- ✅ All specified tests implemented and passing

⸻

Prompt 2 — Reminders as "nudge" scheduler, per-user prefs, STOP compliance

Goal: Convert the nudge feature into an opt-in reminder system with simple schedules. Expose config. Respect STOP/START.

Audit mode:
•Find current AI nudge triggers and rate limits.
•Locate user model or settings table for per-user flags.
•Find STOP/START handling and any consent logs.

Act mode:
•Schema:
•Add users.reminders_enabled BOOL DEFAULT 0
•Add users.reminders_when TEXT NULL values in {MORNING,EVENING,HH:MM} 24h
•Commands:
•REMINDERS ON → set reminders_enabled=1
•REMINDERS OFF → set reminders_enabled=0
•REMINDERS WHEN MORNING|EVENING|HH:MM → set window
•Scheduler:
•Add CLI bin/run_reminders.php:
•For each user with reminders enabled, compute if "now" matches their window:
•MORNING = 07:30 local
•EVENING = 20:00 local
•HH:MM exact
•Use per-user local tz if available, else server tz.
•Skip users with phone_opted_out=1.
•Respect sms.inbound_rate_window_sec indirectly by spacing sends; limit one reminder per user per day; store a reminders_log row to enforce.
•Add reminders_log(user_id, sent_on_date, when).
•Message:
•Template key REMINDER: "Reminder to report steps. Reply with a number or HELP."
•STOP/START:
•Ensure outbound send path blocks if user opted out.
•Record STOP/START in sms_consent_log.
•Config:
•Add admin settings:
•reminders.default_morning=07:30
•reminders.default_evening=20:00
•Tests:
•Turn on reminders, schedule MORNING, simulate time → one send, not twice.
•STOP prevents reminders.
•START re-enables.
•HH:MM parsing and edge cases.

**COMPLETED** - Prompt 2 has been implemented with the following results:

- ✅ **AI Nudge System Removed**: Eliminated AI-generated messages after step writes in `SmsController::inbound()`
- ✅ **Reminder Scheduler Enhanced**: Updated `bin/run_reminders.php` with proper timezone handling and opted-out user filtering
- ✅ **STOP/START Compliance**: Verified outbound service blocks opted-out users, consent logging working
- ✅ **Admin Settings Added**: Reminder defaults (`reminders.default_morning`, `reminders.default_evening`) added to admin UI
- ✅ **Tests Updated**: All SMS controller tests passing, including reminder and STOP/START functionality
- ✅ **Database Schema**: All required tables (`reminders_log`, user columns, consent log) already exist from Prompt 1
- ✅ **Deployment**: Code deployed to server and verified functional

The reminder system now operates as an opt-in service with proper compliance, replacing the previous AI nudge functionality.

⸻

Prompt 3 — Admin SMS web UI, visibility, attachments, send tool, settings exposure

Goal: Build an admin SMS page to view a person's recent messages and send new ones with attachments. Expose SMS settings.

Audit mode:
•Identify admin area route structure and auth guard.
•Find where inbound/outbound audits and attachments are stored.
•Confirm a media upload helper exists or create one.

Act mode:
•Admin page: /admin/sms
•Search box or dropdown to select a user.
•Panel shows last 50 messages for that user, newest first:
•Timestamp, direction (inbound/outbound), body, delivery status, link or inline preview for attachments (images, PDFs as links).
•Composer form:
•Textarea for message.
•File input for up to 3 attachments. Upload to assets/sms/{user_id}/YYYY/MM/uuid.ext.
•On send, call Outbound::sendSMS() with media URLs if supported, else fall back to text only.
•Show STOP state and a toggle to re-opt-in the user (START), with compliance note.
•Settings page additions:
•Toggle sms.admin_prefix_enabled
•Text sms.admin_password (masked)
•Text app.public_base_url
•Numbers: sms.inbound_rate_window_sec, sms.ai_rate_window_sec, sms.backfill_days
•Times: reminders.default_morning, reminders.default_evening
•Hidden from UI but kept: per-user ai_enabled remains admin-only control on the user detail page. Not in HELP.
•HELP content update in code and Admin UI preview.
•Attachments:
•Store media metadata in sms_outbound_audit.meta and inbound media in sms_audit.meta as JSON with URLs and MIME types.
•Sanitize filenames and validate MIME on upload.
•Tests:
•Admin can view recent messages and attachments render.
•Admin can send a message with one image; audit row recorded; Twilio called with MediaUrl.
•Settings update persists and affects behavior (admin prefix, base URL).
•Non-admin cannot access /admin/sms.

**COMPLETED** - Prompt 3 has been implemented with the following results:

- ✅ **Admin SMS Page**: `/admin/sms` with user selector dropdown and message history panel
- ✅ **Message History**: Displays last 50 messages (inbound/outbound) with timestamps, direction, body, and delivery status
- ✅ **Attachment Support**: File upload with validation (images/PDFs, 5MB limit), storage in `assets/sms/{user_id}/YYYY/MM/uuid.ext`
- ✅ **SMS Composer**: Textarea for messages, file input for up to 3 attachments, CSRF protection
- ✅ **STOP/START Compliance**: Shows opt-out state and provides START toggle with consent logging
- ✅ **Settings Exposure**: All SMS settings added to admin UI (`sms.admin_prefix_enabled`, `sms.admin_password`, etc.)
- ✅ **Attachment Metadata**: Stored in `sms_outbound_audit.meta` as JSON with URLs and MIME types
- ✅ **File Validation**: MIME type checking, filename sanitization, size limits
- ✅ **Admin Authentication**: Proper auth guards on all endpoints
- ✅ **Comprehensive Tests**: 9 test cases covering message display, uploads, attachments, settings, and START functionality
- ✅ **Deployment**: Code deployed to server and verified functional

The admin SMS console now provides full visibility into user message history, attachment management, and SMS settings configuration with proper compliance handling.

These three prompts implement your admin SMS gate, revised commands, interests, award link behavior, reminders, and the admin SMS console, while keeping AI toggles admin-only and HELP clean.
