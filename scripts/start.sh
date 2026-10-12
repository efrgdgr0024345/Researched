#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-8080}"

echo "Researched Idea Workbench: http://${HOST}:${PORT}"
exec php -S "${HOST}:${PORT}" -t public
