#!/bin/sh
set -eu

# Weekly rollover: finalize latest week and create next week's roster
# - Safe to run multiple times. Finalize updates snapshot; create is idempotent.

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BASE_URL="${BASE_URL-}"
if [ -z "$BASE_URL" ]; then BASE_URL="http://localhost/dev/html/walk"; fi

# Read a key from environment or .env.local/.env
read_env_var() {
  key="$1"
  val=""
  # from process env
  eval "val=\${$key:-}"
  # from .env.local
  if [ -z "$val" ] && [ -f "$ROOT_DIR/.env.local" ]; then
    val="$(grep -E "^${key}=" "$ROOT_DIR/.env.local" | tail -n1 | sed 's/^[^=]*=//')"
  fi
  # from .env
  if [ -z "$val" ] && [ -f "$ROOT_DIR/.env" ]; then
    val="$(grep -E "^${key}=" "$ROOT_DIR/.env" | tail -n1 | sed 's/^[^=]*=//')"
  fi
  printf '%s' "$val"
}

ADMIN_USER="${ADMIN_USER-}"
if [ -z "$ADMIN_USER" ]; then ADMIN_USER="$(read_env_var ADMIN_USER)"; fi
ADMIN_PASS="${ADMIN_PASS-}"
if [ -z "$ADMIN_PASS" ]; then ADMIN_PASS="$(read_env_var ADMIN_PASS)"; fi

if [ -z "$ADMIN_USER" ] || [ -z "$ADMIN_PASS" ]; then
  echo "[weekly_rollover] Missing ADMIN_USER/ADMIN_PASS in env or .env files" >&2
  exit 1
fi

echo "[weekly_rollover] Using BASE_URL=$BASE_URL"

# Fetch CSRF token from admin index
get_csrf() {
  curl -fsS -u "$ADMIN_USER:$ADMIN_PASS" "$BASE_URL/admin/index.php" \
    | sed -n 's/.*const CSRF = \"\([^\"]*\)\".*/\1/p' | head -n1
}
CSRF="$(get_csrf)"
if [ -z "$CSRF" ]; then
  echo "[weekly_rollover] Failed to fetch CSRF token from admin/index.php" >&2
  exit 1
fi

# Get latest week from API
weeks_json="$(curl -fsS "$BASE_URL/api/weeks.php")"
latest_week="$(printf '%s' "$weeks_json" | php -r '
  $j = json_decode(stream_get_contents(STDIN), true);
  if (!is_array($j) || empty($j["weeks"])) { exit(2); }
  echo isset($j["weeks"][0]["week"]) ? $j["weeks"][0]["week"] : (isset($j["weeks"][0]["starts_on"]) ? $j["weeks"][0]["starts_on"] : "");
' || true)"

if [ -z "$latest_week" ]; then
  echo "[weekly_rollover] No latest week found; aborting" >&2
  exit 2
fi

echo "[weekly_rollover] Latest week: $latest_week"

# Finalize latest week (creates/updates snapshot)
curl -fsS -u "$ADMIN_USER:$ADMIN_PASS" \
  -H "X-CSRF: $CSRF" \
  -d "csrf=$CSRF" -d "action=finalize" -d "week=$latest_week" \
  "$BASE_URL/api/entries_finalize.php" >/dev/null || true

# Compute next week start date (ISO)
next_week="$(php -r 'echo date("Y-m-d", strtotime(($argv[1] !== null ? $argv[1] : "") . " +7 days"));' -- "$latest_week")"
echo "[weekly_rollover] Next week: $next_week"

# Create next week and copy roster from prior week
# 1) Create/update the target week label
curl -fsS -u "$ADMIN_USER:$ADMIN_PASS" \
  -H "X-CSRF: $CSRF" \
  -d "csrf=$CSRF" -d "action=create" -d "date=$next_week" -d "label=$next_week" \
  "$BASE_URL/api/weeks.php" >/dev/null || true
# 2) Copy roster from previous week (auto-detected as the latest week < target)
curl -fsS -u "$ADMIN_USER:$ADMIN_PASS" \
  -H "X-CSRF: $CSRF" \
  -d "csrf=$CSRF" -d "target=$next_week" \
  "$BASE_URL/api/entries_copy_roster.php" >/dev/null || true

echo "[weekly_rollover] Done"
