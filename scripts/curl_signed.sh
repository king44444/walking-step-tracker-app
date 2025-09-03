#!/usr/bin/env bash
set -euo pipefail
: "${URL:?set URL}"; : "${AUTH:?set AUTH}"

SIG=$(python3 "$(dirname "$0")/twilio_sign.py")

curl -sS -X POST "$URL" \
  -H "X-Twilio-Signature: $SIG" \
  --data MessageSid=SM_test123 \
  --data MessageStatus=delivered \
  --data To=+13855032310 \
  --data From=+18015550123 -i
