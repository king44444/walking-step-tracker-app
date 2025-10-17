#!/bin/sh
set -eu

# Test AI toggle + SMS flow
# Usage:
#   BASE_URL=https://example.com/walk FROM=+1XXXXXXXXXX ./scripts/test_ai_flow.sh
# If run on the server, you can omit BASE_URL and it will default to http://localhost/dev/html/walk

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"

: "${BASE_URL:=http://localhost/dev/html/walk}"

read_env_var() {
  key="$1"
  val=""
  eval "val=\${$key:-}"
  if [ -z "$val" ] && [ -f "$ROOT_DIR/.env.local" ]; then
    val="$(grep -E "^${key}=" "$ROOT_DIR/.env.local" | tail -n1 | sed 's/^[^=]*=//')"
  fi
  if [ -z "$val" ] && [ -f "$ROOT_DIR/.env" ]; then
    val="$(grep -E "^${key}=" "$ROOT_DIR/.env" | tail -n1 | sed 's/^[^=]*=//')"
  fi
  printf '%s' "$val"
}

ADMIN_USER="${ADMIN_USER-}"
if [ -z "$ADMIN_USER" ]; then ADMIN_USER="$(read_env_var ADMIN_USER)"; fi
ADMIN_PASS="${ADMIN_PASS-}"
if [ -z "$ADMIN_PASS" ]; then ADMIN_PASS="$(read_env_var ADMIN_PASS)"; fi
INTERNAL_API_SECRET="${INTERNAL_API_SECRET-}"
if [ -z "$INTERNAL_API_SECRET" ]; then INTERNAL_API_SECRET="$(read_env_var INTERNAL_API_SECRET)"; fi

# Fetch CSRF token from admin index
get_csrf() {
  curl -fsS -u "$ADMIN_USER:$ADMIN_PASS" "$BASE_URL/admin/index.php" \
    | sed -n 's/.*const CSRF = \"\([^\"]*\)\".*/\1/p' | head -n1
}
CSRF="$(get_csrf)"
if [ -z "$CSRF" ]; then
  echo "[test] Failed to fetch CSRF token from admin/index.php" >&2
fi

if [ -z "${FROM-}" ]; then
  echo "Set FROM to an enrolled E.164 phone (e.g., FROM=+15551234567)" >&2
  exit 1
fi

echo "[test] BASE_URL=$BASE_URL FROM=$FROM"

toggle() {
  val="$1"
  # Use new settings endpoint
  curl -fsS -u "$ADMIN_USER:$ADMIN_PASS" \
    -H "Content-Type: application/json" -H "X-CSRF: $CSRF" \
    -d "{\"key\":\"ai.enabled\",\"value\":$val,\"csrf\":\"$CSRF\"}" \
    "$BASE_URL/api/settings_set.php" >/dev/null
}

get_setting() {
  curl -fsS -u "$ADMIN_USER:$ADMIN_PASS" "$BASE_URL/api/settings_get.php"
}

send_sms() {
  body="$1"
  hdr=( )
  if [ -n "$INTERNAL_API_SECRET" ]; then
    hdr+=( -H "X-Internal-Secret: $INTERNAL_API_SECRET" )
  fi
  curl -fsS -X POST "${hdr[@]}" "$BASE_URL/api/sms.php" --data-urlencode "From=$FROM" --data-urlencode "Body=$body"
}

echo "[test] Turning AI OFF"
toggle 0
echo "[test] Setting: $(get_setting)"
echo "[test] Sending SMS '111'"
send_sms "111" || true

echo "[test] Turning AI ON"
toggle 1
echo "[test] Setting: $(get_setting)"
echo "[test] Sending SMS '222'"
send_sms "222" || true

echo "[test] Fetch last AI log entries"
curl -fsS -u "$ADMIN_USER:$ADMIN_PASS" "$BASE_URL/api/ai_log.php" || true
