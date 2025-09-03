#!/usr/bin/env bash
set -euo pipefail

LOCAL_ROOT="/Users/michaelking/Documents/projects/king-walk-week"
#PI_HOST="piwebserver.local"
PI_HOST="192.168.0.103"
PI_USER="mike"
REMOTE_ROOT="/var/www/public_html/dev/html/walk"
WEB_USER="www-data"

echo "Syncing files..."
rsync -avz --delete \
  --exclude '.git' --exclude '.DS_Store' --exclude 'site/_bak' --exclude 'data/walkweek.sqlite' \
  "${LOCAL_ROOT}/" "${PI_USER}@${PI_HOST}:${REMOTE_ROOT}/"

echo "Fixing permissions..."
ssh "${PI_USER}@${PI_HOST}" "sudo chown -R ${WEB_USER}:${WEB_USER} '${REMOTE_ROOT}/data' '${REMOTE_ROOT}/data/walkweek.sqlite' && sudo chmod 775 '${REMOTE_ROOT}/data' && sudo chmod 664 '${REMOTE_ROOT}/data/walkweek.sqlite' || true"

echo "Run migration..."
ssh "${PI_USER}@${PI_HOST}" 'cd ${REMOTE_ROOT} && php api/migrate.php' || true
echo
echo "Weeks JSON:"
curl -sS 'http://'"${PI_HOST}"'/dev/html/walk/api/weeks.php' || true
echo
echo "Done."
