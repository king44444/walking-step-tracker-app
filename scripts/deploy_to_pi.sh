#!/usr/bin/env bash
set -euo pipefail

LOCAL_ROOT="/Users/michaelking/Documents/projects/king-walk-week"
#PI_HOST="piwebserver.local"
PI_HOST="192.168.0.103"
PI_USER="mike"
REMOTE_ROOT="/var/www/public_html/dev/html/walk"
WEB_USER="www-data"

TS=$(date -u +"%Y%m%dT%H%M%SZ")
BACKUP_DIR="${LOCAL_ROOT}/backup"
mkdir -p "${BACKUP_DIR}"
BACKUP_TAR="${BACKUP_DIR}/data-backup-${TS}.tar.gz"
BACKUP_TMP="${BACKUP_DIR}/data-backup-${TS}"

echo "Backing up remote data to ${BACKUP_TAR}..."
# Stream a tar of the remote data dir; fall back to rsync if sudo tar fails.
rm -f "${BACKUP_TAR}"
rm -rf "${BACKUP_TMP}"
if ssh "${PI_USER}@${PI_HOST}" "sudo mkdir -p '${REMOTE_ROOT}/data' && sudo tar -C '${REMOTE_ROOT}' -czf - data" > "${BACKUP_TAR}" 2>/dev/null; then
  echo "Backup created: ${BACKUP_TAR}"
else
  echo "Remote sudo tar failed; falling back to rsync pull..."
  if rsync -avz --rsync-path="sudo rsync" "${PI_USER}@${PI_HOST}:${REMOTE_ROOT}/data/" "${BACKUP_TMP}/"; then
    echo "Rsync (sudo) pull succeeded"
  else
    echo "Rsync (sudo) failed; trying plain rsync pull"
    if ! rsync -avz "${PI_USER}@${PI_HOST}:${REMOTE_ROOT}/data/" "${BACKUP_TMP}/"; then
      echo "All backup methods failed. Aborting deploy."
      exit 1
    fi
  fi
  tar -C "${BACKUP_DIR}" -czf "${BACKUP_TAR}" "$(basename "${BACKUP_TMP}")"
  echo "Backup created (rsync fallback): ${BACKUP_TAR}"
fi

# Verify backup file exists and is non-empty
if [ ! -s "${BACKUP_TAR}" ]; then
  echo "Backup file ${BACKUP_TAR} not created or empty. Aborting."
  exit 1
fi

echo "Syncing files (excluding live DB)..."
rsync -avz --delete \
  --rsync-path="sudo rsync" \
  --exclude '.git' \
  --exclude '.DS_Store' \
  --exclude '.env.local' \
  --exclude 'site/_bak' \
  --exclude 'backup/' \
  --exclude 'site/assets/awards/' \
  --exclude 'site/assets/users/' \
  --exclude 'data/' \
  "${LOCAL_ROOT}/" "${PI_USER}@${PI_HOST}:${REMOTE_ROOT}/"

echo "Ensure api/data -> ../data symlink and data dir exists..."
ssh "${PI_USER}@${PI_HOST}" "sudo bash -lc '
  cd \"${REMOTE_ROOT}\"
  # Ensure data dir exists and has correct ownership
  mkdir -p data
  chown -R ${WEB_USER}:${WEB_USER} data || true
  # Replace any non-symlink api/data with a symlink
  if [ -e api/data ] && [ ! -L api/data ]; then
    rm -rf api/data
  fi
  ln -sfn ../data api/data
  # Ensure site asset award directories exist and are writable by web user
  mkdir -p site/assets/awards site/assets/users
  chown -R ${WEB_USER}:${WEB_USER} site/assets/awards site/assets/users || true
  find site/assets/awards site/assets/users -type d -exec chmod 775 {} \; || true
'"

echo "Fix permissions on data dir, DB and deployed scripts..."
ssh "${PI_USER}@${PI_HOST}" "bash -lc '
  sudo chown -R ${WEB_USER}:${WEB_USER} \"${REMOTE_ROOT}/data\" || true
  sudo find \"${REMOTE_ROOT}/data\" -type d -exec chmod 775 {} \; || true
  if [ -f \"${REMOTE_ROOT}/data/walkweek.sqlite\" ]; then
    sudo chmod 664 \"${REMOTE_ROOT}/data/walkweek.sqlite\"
  fi

  # Make deployed shell scripts executable
  if [ -d \"${REMOTE_ROOT}/scripts\" ]; then
    sudo find \"${REMOTE_ROOT}/scripts\" -type f -name \"*.sh\" -exec chmod a+x {} \; || true
  fi

  # Make api helper python scripts executable
  if [ -d \"${REMOTE_ROOT}/api\" ]; then
    sudo find \"${REMOTE_ROOT}/api\" -type f -name \"*.py\" -exec chmod a+x {} \; || true
  fi
'"

echo "Run database migrations on server..."
ssh "${PI_USER}@${PI_HOST}" "bash -lc '
  cd \"${REMOTE_ROOT}\" && php api/migrate.php >/dev/null 2>&1 || true
'"

echo "Prepare Nginx snippet for /dev/html/walk/api/* routing (manual include required)..."
ssh "${PI_USER}@${PI_HOST}" "sudo bash -lc '
  set -e
  mkdir -p /etc/nginx/snippets
  SNIP=/etc/nginx/snippets/walk_api_routes.conf
  cat > \"\$SNIP\" <<CONF
  # Include this inside the appropriate server { } block
  location ^~ /dev/html/walk/api/ {
      try_files \$uri /dev/html/walk/public/index.php;
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME /var/www/public_html/dev/html/walk/public/index.php;
      fastcgi_param QUERY_STRING \$query_string;
      fastcgi_param REQUEST_METHOD \$request_method;
      fastcgi_param CONTENT_TYPE \$content_type;
      fastcgi_param CONTENT_LENGTH \$content_length;
      fastcgi_pass unix:/run/php/php8.2-fpm.sock;
  }
CONF
  # Remove any previously installed invalid conf.d file to avoid nginx -t failures
  rm -f /etc/nginx/conf.d/walk_api_routes.conf || true
'"

echo "Restart php-fpm..."
ssh "${PI_USER}@${PI_HOST}" "sudo systemctl restart php8.2-fpm"

# Optional diagnostics (no migration on deploy)
echo
echo "Quick DB check (weeks):"
curl -sS "http://${PI_HOST}/dev/html/walk/api/weeks.php" || true
echo
echo "Weeks JSON:"
curl -sS "http://${PI_HOST}/dev/html/walk/api/weeks.php" || true
echo
echo "Health:"
curl -sS "http://${PI_HOST}/dev/html/walk/api/health.php" || true
echo
echo "Backup saved at: ${BACKUP_TAR}"
echo "Done."
