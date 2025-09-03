#!/usr/bin/env bash
set -euo pipefail
cd /var/www/public_html/dev/html/walk
mkdir -p backup
ts=$(date +%F-%H%M%S)
zip -j "backup/walkweek-$ts.zip" data/walkweek.sqlite
# keep last 14 zips; delete older
ls -1t backup/walkweek-*.zip | tail -n +15 | xargs -r rm -f
