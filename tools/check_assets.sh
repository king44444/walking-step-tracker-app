#!/usr/bin/env bash
set -euo pipefail
BASE="${1:-http://localhost/dev/html/walk/site}"
curl -fsS "$BASE/assets/css/app.css" >/dev/null
curl -fsS "$BASE/assets/js/app/main.js" >/dev/null
echo "OK"
