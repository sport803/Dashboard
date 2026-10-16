#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_DIR="$ROOT/wordpress-plugin/sports803-dashboard"
OUT_DIR="$ROOT/dist"
mkdir -p "$OUT_DIR"
cp "$ROOT/index.html" "$PLUGIN_DIR/assets/dashboard.html"
rm -f "$OUT_DIR/sports803-dashboard.zip"
cd "$ROOT/wordpress-plugin"
zip -qr "$OUT_DIR/sports803-dashboard.zip" sports803-dashboard
printf 'Created %s\n' "$OUT_DIR/sports803-dashboard.zip"
