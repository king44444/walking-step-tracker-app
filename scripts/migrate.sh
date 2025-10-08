#!/usr/bin/env bash
set -euo pipefail
vendor/bin/phinx migrate -c phinx.php
