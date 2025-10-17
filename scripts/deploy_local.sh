#!/usr/bin/env bash
set -euo pipefail

# Convenience wrapper for deploy_to_pi.sh with local network defaults.
# Override any variable by exporting it before running this script or by
# passing PI_HOST/PI_USER/REMOTE_ROOT inline.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

: "${PI_HOST:=192.168.0.103}"
: "${PI_USER:=mike}"
: "${REMOTE_ROOT:=/var/www/public_html/dev/html/walk}"
: "${REMOTE_URI_PREFIX:=/dev/html/walk}"

export PI_HOST PI_USER REMOTE_ROOT REMOTE_URI_PREFIX

exec "${SCRIPT_DIR}/deploy_to_pi.sh" "$@"
