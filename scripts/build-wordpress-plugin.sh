#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT/dist"
mkdir -p "$OUT_DIR"
rm -f "$OUT_DIR/sports803-dashboard.zip"
cd "$ROOT/wordpress-plugin"
zip -qr "$OUT_DIR/sports803-dashboard.zip" sports803-dashboard
printf 'Created %s\n' "$OUT_DIR/sports803-dashboard.zip"
