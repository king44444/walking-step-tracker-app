# Agent Instructions

## Purpose
Capture repo conventions, deployment facts, and "gotchas" for future agents/humans working on the King Walk Week project.

## Critical Workflow Rules
**ALWAYS deploy and commit after making code changes:**
1. Deploy first: `./scripts/deploy_to_pi.sh`
2. Then commit: `git add -A && git commit -m "Description" && git push`
3. Never skip these steps after modifying code

## Quick Deploy & Debug via SSH

### Deploy to Server
```bash
# From local machine - deploys code and restarts services
./scripts/deploy_to_pi.sh

# Check deployment status
ssh mike@192.168.0.103 "cd /var/www/public_html/dev/html/walk && curl -s api/weeks.php | jq ."
```

## Reminders Scheduler (Cron)
- The reminder system is driven by `bin/run_reminders.php` and must be scheduled to run every minute.
- Server log directory: `data/logs` (created automatically in the steps below).

### Install/Verify Cron on Server
```bash
# SSH to the server
ssh mike@192.168.0.103
cd /var/www/public_html/dev/html/walk

# Ensure log directory exists
mkdir -p data/logs

# Add a per-minute cron to run reminders (user crontab)
crontab -l 2>/dev/null | grep -q 'bin/run_reminders.php' || \
  (crontab -l 2>/dev/null; echo '*/1 * * * * /usr/bin/php /var/www/public_html/dev/html/walk/bin/run_reminders.php >> /var/www/public_html/dev/html/walk/data/logs/reminders.log 2>&1') | crontab -

# Confirm entry exists
crontab -l | sed -n '1,200p'

# Tail logs
tail -f data/logs/reminders.log
```

### Manual Validation
```bash
# Force Mike's reminder to current minute and enable
php -r "require 'vendor/autoload.php'; \App\Core\Env::bootstrap('.'); $pdo=\App\Config\DB::pdo(); $now=(new DateTime('now', new DateTimeZone(date_default_timezone_get())))->format('H:i'); $pdo->prepare(\"UPDATE users SET reminders_enabled=1, reminders_when=? WHERE name='Mike'\")->execute([$now]); echo \"set $now\\n\";"

# Run the scheduler once
php bin/run_reminders.php

# Inspect outbound audit for errors
php -r "require 'vendor/autoload.php'; \App\Core\Env::bootstrap('.'); $pdo=\App\Config\DB::pdo(); foreach($pdo->query(\"SELECT created_at,to_number,http_code,sid,error FROM sms_outbound_audit ORDER BY id DESC LIMIT 10\") as $r){echo json_encode($r),PHP_EOL;}"
```

Notes
- `Outbound::sendSMS` requires `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, and `TWILIO_FROM_NUMBER` to be set in the PHP-FPM/CLI env (loaded via `.env` with `App\Core\Env::bootstrap`).
- Reminders are sent only once per user per day (tracked by `reminders_log`). Users with `phone_opted_out=1` are skipped.

### Current Status (Verified)
- Cron installed for user `mike`: runs every minute.
- DB tables present: `reminders_log`, `sms_consent_log`; users table has `phone_opted_out`.
- Scheduler timezone: uses `WALK_TZ` via `now_in_tz()`.
- Outbound SMS: CLI can read Twilio creds via `$_ENV` or fallback to `.env.local` parse.
- End-to-end test (Mike) succeeded; one reminder sent and logged.

### Troubleshooting Quick-Checks
- If `bin/run_reminders.php` says “Failed to send reminder … Missing TWILIO_*”:
  - Ensure `.env.local` on server includes: `TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM_NUMBER`.
  - Re-run: `php bin/run_reminders.php` and inspect `sms_outbound_audit`.
- If no reminders sent at expected time:
  - Confirm `WALK_TZ` and `users.reminders_when` values match intended local time.
  - Check cron: `crontab -l` and tail `data/logs/reminders.log`.

## Environment Files & Secrets
- The app loads `.env` first, then `.env.local` (overrides). Keep server-only secrets in `.env.local` on the server.
- Deploy script excludes `.env.local` to avoid overwriting secrets during rsync.
- A template for production/server configuration is provided: `.env.server.example` — copy and edit on the server as `.env.local`.
- If you see a stray `env.local` (missing leading dot), it is unused. Rename it to `.env.local` or remove it.

### SSH Debug Commands
```bash
# Connect to server
ssh mike@192.168.0.103
cd /var/www/public_html/dev/html/walk

# Check logs
tail -f /var/log/nginx/error.log
tail -20 data/logs/ai/award_images.log

# Test database
php -r "require 'vendor/autoload.php'; \App\Core\Env::bootstrap('.'); echo \App\Config\DB::pdo()->query('SELECT 1')->fetchColumn() ? 'DB OK' : 'DB FAIL';"

# Run PHP tests
./vendor/bin/phpunit tests/ --testdox

# Test SMS endpoints
SMS_SMOKE_BASE_URL=http://localhost ./bin/smoke_sms.sh

# Test award generation
php -r "
require 'vendor/autoload.php';
require 'api/lib/settings.php';
require 'api/lib/ai_images.php';
\App\Core\Env::bootstrap('.');
use \App\Config\DB;
$result = ai_image_generate(['user_id' => 1, 'user_name' => 'Test', 'award_kind' => 'weekly_steps', 'milestone_value' => 10000]);
echo 'Result: ' . ($result['ok'] ? 'SUCCESS' : 'FAILED') . PHP_EOL;
if ($result['ok']) echo 'Path: ' . $result['path'] . PHP_EOL;
"

# Check settings
php -r "
require 'api/lib/settings.php';
echo 'ai.enabled: ' . setting_get('ai.enabled', '1') . PHP_EOL;
echo 'ai.award.enabled: ' . setting_get('ai.award.enabled', '1') . PHP_EOL;
echo 'ai.image.provider: ' . setting_get('ai.image.provider', 'local') . PHP_EOL;
echo 'ai.image.model: ' . setting_get('ai.image.model', '') . PHP_EOL;
"
```

### Local Development Testing
```bash
# Run all tests
./vendor/bin/phpunit tests/

# Run specific test suite
./vendor/bin/phpunit tests/SmsControllerTest.php --testdox

# Test weeks API
./scripts/test_weeks_api.sh

# Test SMS smoke tests
SMS_SMOKE_BASE_URL=http://localhost ./bin/smoke_sms.sh
```

## Pathing Rules
- Public site pages under `site/` must not assume domain-root. Use relative paths.
- Site asset policy:
  - Load UI from `../public/assets/` in `site/index.html` and `site/lifetime.html`
  - CSS: `../public/assets/css/app.css`
  - JS: `../public/assets/js/app/main.js`
- Admin asset policy:
  - Admin pages live under `/admin/`. Use `../site/assets/` for shared images
  - API pathing from admin: use `../api/...` (no domain-root assumptions)

## API Contracts
- `api/weeks.php`: GET returns `{ok, weeks[]}`, POST `action=create|delete`
- `api/data.php`: Returns week data with normalization
- SMS endpoints: `api/sms.php`, `api/send_sms.php`, `api/sms_status.php` (deprecated - use router `/api/...` instead; these delegate to SmsController and will be removed)
- Award generation: `api/award_generate.php` (admin only)

## Database & Migrations
- SQLite database: `data/walkweek.sqlite`
- Migrations in `database/migrations/`
- Run migrations: `php api/migrate.php`
- Settings table: Stores app configuration
- SMS tables: `sms_audit`, `sms_outbound_audit`, `message_status`

## Testing & Quality
- PHPUnit tests in `tests/` directory
- SMS smoke tests: `bin/smoke_sms.sh`
- API testing: `scripts/test_weeks_api.sh`
- Award generation testing: Direct PHP execution
- Code style: PHPStan and PHPCS configured

## Best Practices
- Use relative paths only (no absolute `/assets/...`)
- All database operations through PDO with prepared statements
- Error logging to appropriate log files
- CSRF protection on admin endpoints
- Input validation and sanitization
- Graceful fallbacks for external services (AI image generation)
- Comprehensive test coverage for critical paths

## Quick Tasks for Future Agents
- Fix asset path regressions: follow Pathing Rules above
- Add features: Follow existing API contracts and test patterns
- Update UI: Modify `public/assets/` source files, not site copies
- Debug issues: Use SSH commands above for server-side debugging
- Test changes: Run full test suite before deploying
