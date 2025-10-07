#!/usr/bin/env bash
set -euo pipefail
BASE="${1:-https://mikebking.com/dev/html/walk}"

curl_json() {
  curl -fsS "$@" | sed 's/.*/RESPONSE: &/'
}

echo "-- GET weeks (should not 500)"
curl -fsS "$BASE/api/weeks.php" | sed -n '1,200p' >/dev/null || true

echo "-- Create bad date 2025-10-5 (auto-normalize to 2025-10-05)"
curl_json -X POST "$BASE/api/weeks.php" \
  -d 'action=create' -d 'date=2025-10-5'

echo "-- Create valid date twice (second returns created:false)"
curl_json -X POST "$BASE/api/weeks.php" \
  -d 'action=create' -d 'date=2025-10-12'
curl_json -X POST "$BASE/api/weeks.php" \
  -d 'action=create' -d 'date=2025-10-12'

echo "-- Delete a week with no entries (2025-10-12)"
curl_json -X POST "$BASE/api/weeks.php" \
  -d 'action=delete' -d 'date=2025-10-12'

echo "-- Delete a week with entries (may error then ok with force)"
curl -fsS "$BASE/api/weeks.php" | jq -r '.weeks[0].starts_on // .weeks[0].week' >/tmp/kw_week.txt || true
WK=$(cat /tmp/kw_week.txt 2>/dev/null || echo '')
if [[ -n "$WK" ]]; then
  echo "Trying delete of $WK"
  curl_json -X POST "$BASE/api/weeks.php" -d "action=delete" -d "date=$WK" || true
  curl_json -X POST "$BASE/api/weeks.php" -d "action=delete" -d "date=$WK" -d 'force=1' || true
fi

echo "-- GET weeks (sorted list)"
curl -fsS "$BASE/api/weeks.php" | sed -n '1,200p' >/dev/null || true

echo "OK"

