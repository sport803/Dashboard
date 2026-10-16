#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
find wordpress-plugin -name '*.php' -print0 | xargs -0 -n1 php -l
php wordpress-plugin/tests/plugin-smoke.php
bash scripts/build-wordpress-plugin.sh
unzip -t dist/sports803-dashboard.zip
