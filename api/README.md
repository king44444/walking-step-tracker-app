# Walk Week API — SMS / Twilio

Overview
- Webhook endpoint: `https://mikebking.com/dev/html/walk/api/sms.php`
- Purpose: accept Twilio SMS posts with step counts and upsert into the active week entries.

Environment
- api/.env.local should contain:
  - `WALK_TZ` (e.g. `America/Denver`)
  - `TWILIO_AUTH_TOKEN` (set to Twilio auth token to enable signature verification; leave empty to disable)

SMS input rules
- Accepted body formats:
  - `12345`
  - `Tue 12345` (day override, 3–9 alpha characters allowed; case-insensitive)
- Only one numeric group allowed. If more than one number is present the request is rejected.
- Steps must be integer between 0 and 200000.

Rate limiting
- A 60-second rate limit is enforced per phone number based on successful prior submissions.
- If a recent (<=60s) sms_audit row with status `ok` exists for the same From number, endpoint returns 429 with body:
  - `Slow down. Try again in a minute.`

Signature verification
- If `TWILIO_AUTH_TOKEN` is non-empty, Twilio request signatures are verified.
- On invalid signature, endpoint returns HTTP 403 and body:
  - `Forbidden.`

Typical responses and error messages
- Success:
  - `Recorded 12,345 for Mike on today.` (or `on yesterday.` depending on noon rule)
  - If a day override was provided: `on tuesday.` (override is lowercased)
- Unknown number (phone not enrolled):
  - `Number not recognized. Ask admin to enroll your phone.`
- No active week:
  - `No active week.`
- Bad day token:
  - `Unrecognized day. Use Mon..Sat or leave it out.`
- Too many numbers in message:
  - `Send one number like 12345 or 'Tue 12345'.`
- Invalid steps:
  - `Invalid steps.`
- Bad request (missing From or Body):
  - `Bad request.`

Admin: enrolling phones
- Use the admin UI `admin/phones.php` (requires admin basic auth). It lists users and allows:
  - Save: store the provided string into `users.phone_e164`.
  - Normalize: reformats the input via `to_e164()` and saves.
  - Clear: sets `phone_e164` to NULL.
- When enrolling phones, save E.164 (`+1XXXXXXXXXX`) form where possible.

Testing
- Example curl (replace From number as needed):
```bash
curl -i https://mikebking.com/dev/html/walk/api/sms.php \
  --data-urlencode "From=+18015551234" \
  --data-urlencode "Body=12345"
```

Logs / audit
- Every incoming request is logged to `sms_audit` with a `status` value that indicates the processing outcome (e.g., `ok`, `bad_signature`, `rate_limited`, `unknown_number`, `invalid_steps`, etc.).

Maintenance
- Backup script: `scripts/backup_db.sh` (zips DB to `backup/` and keeps last 14 zips).
- Audit rotation: `scripts/rotate_audit.php` deletes `sms_audit` rows older than 90 days.
- Example cron entries provided in `crontab.example`.

Notes
- The API is intentionally lightweight (plain text responses). Do not expose `api/.env.local` contents.

Signature Debug Playbook
- Purpose: reproduce and debug Twilio X-Twilio-Signature mismatches behind Cloudflare / proxies.

1) Enable debug in PHP-FPM env
- On the Raspberry Pi, edit your PHP-FPM pool (e.g. `/etc/php/8.2/fpm/pool.d/www.conf`) and add:
  env[TWILIO_AUTH_TOKEN]="<real-token>"
  env[TWILIO_SIG_DEBUG]="1"
  env[TWILIO_TEST_MODE]="0"
  ; optional for diag protection:
  env[TWILIO_DIAG_TOKEN]="set-a-random-string"
- Restart FPM:
  sudo systemctl restart php8.2-fpm

2) Deploy code changes to the Pi
- From your Mac project root run:
  ./deploy_to_pi.sh
- This syncs the edited files, including:
  - `api/common_sig.php`
  - `api/_sig_diag.php`
  - `api/sms_status.php` and `api/lib/status_callback.php` (now using common verifier)
  - `scripts/twilio_sign.py` and `scripts/curl_signed.sh`

3) Run the signed curl test from your Mac (no PHP locally)
- Set environment and run the helper to POST a signed request to the deployed endpoint:
  export URL="https://mikebking.com/dev/html/walk/api/sms_status.php"
  export AUTH="<same token as on Pi FPM>"
  ./scripts/curl_signed.sh
- Expected: HTTP/2 200 (or HTTP/1.1 200 OK). If you receive 403, continue to step 4.

4) Use the diagnostic endpoint when a test fails
- If a signed test fails, query the diag endpoint to see what the server saw and computed:
  curl -s "https://mikebking.com/dev/html/walk/api/_sig_diag.php?once=$(date +%s)" | jq .
- Output fields to compare:
  - `url_seen` should match your test $URL (scheme, host, path). If different, Cloudflare or proxy forwarded host/proto may differ.
  - `post_sorted` shows sorted "Key:Value" pairs used to build the signature.
  - `joined` is the exact string used to compute the expected signature on the server.
  - `expected` is the server-calculated signature (base64 HMAC-SHA1).
  - `header_sig` is the signature sent by your client.
  - `match` indicates equality.

5) Common mismatch causes
- Wrong URL: host or scheme differs (Cloudflare or proxy changed host header or proto). Check X-Forwarded-Host and X-Forwarded-Proto from the proxy; `api/common_sig.php` prefers those.
- + vs %2B: URL-encoded plus signs in POST data vs literal `+` in form data can change values. Use `--data` (not `--data-urlencode`) when testing if server receives literal `+`.
- Missing header: X-Twilio-Signature not forwarded by proxy or stripped by server.
- Wrong token: AUTH used by signer must exactly match TWILIO_AUTH_TOKEN in FPM env.
- Proxy re-writes: some proxies alter Host or strip request parts; confirm headers preserved.
- Multiple hosts in X-Forwarded-Host: only the first value is used.

6) After success
- Set `TWILIO_SIG_DEBUG=0` in FPM (or remove `api/_sig_diag.php`) and restart FPM:
  sudo systemctl restart php8.2-fpm

7) Local signed test helper
- `scripts/twilio_sign.py` computes a Twilio signature for a fixed POST payload when `URL` and `AUTH` env vars are set.
- `scripts/curl_signed.sh` calls the signer and issues the signed POST. Ensure it's executable:
  chmod +x scripts/curl_signed.sh

Acceptance checklist
- [ ] `./scripts/curl_signed.sh` returns HTTP 200 against `api/sms_status.php`
- [ ] Real Twilio Delivery Status callbacks return 200
- [ ] `api/sms.php` remains functional and unchanged
- [ ] `api/lib/status_callback.php` and `api/sms_status.php` share the same verifier
- [ ] Debug endpoint removed or `TWILIO_SIG_DEBUG=0` after verification
