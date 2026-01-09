# Admin Awards Settings Audit
Date: 2026-01-09

## Scope
Audit of settings and prompts surfaced in `admin/awards_settings.php` and their usage in runtime code.

## Used Settings and Prompts
- `daily.milestones`
  - Used by `site/user.php` to build milestone chips/counts.
  - Exposed via `api/public_settings.php` and consumed by `public/assets/js/app/config.js`.
  - Also used by `api/lifetime.php` (deprecated endpoint).
- `sms.admin_prefix_enabled`, `sms.admin_password`
  - Used in `app/Controllers/SmsController.php` to detect admin-prefixed SMS commands.
- `sms.inbound_rate_window_sec`
  - Used in `app/Controllers/SmsController.php` for inbound rate limiting.
- `sms.ai_rate_window_sec`
  - Used in `app/Controllers/SmsController.php` to rate-limit AI SMS replies.
- `reminders.default_morning`, `reminders.default_evening`
  - Used in `app/Controllers/SmsController.php` for REMINDERS WHEN default times.
  - Used in `bin/run_reminders.php` for MORNING/EVENING scheduling.
- `app.public_base_url`
  - Used in `app/Controllers/SmsController.php` to build lifetime award SMS links.
- `ai.image.prompts.lifetime`
  - Used in `api/lib/ai_images.php` to choose lifetime award prompts.
  - Defaults seeded in `api/lib/settings.php`.

## Unused or Miswired
- `sms.backfill_days`
  - Only read/write via `admin/awards_settings.php`, `api/settings_get.php`, and `api/settings_set.php`.
  - No runtime usage found elsewhere.
- HELP content preview
  - `admin/awards_settings.php` fetches `api/settings_debug.php?key=help_text`, but `api/settings_debug.php` ignores `key` and only returns `ai.*` settings.
  - No `help_text` setting is used anywhere; actual HELP text is hardcoded in `app/Controllers/SmsController.php` (`getHelpText`).

## Notes
- `app.public_base_url` does not affect the SMS footer in `app/Http/Responders/SmsResponder.php`; that responder only uses `SITE_URL` env or `settings.site.url` via `api/lib/config.php`.
