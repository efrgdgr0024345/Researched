#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

if ! command -v php >/dev/null 2>&1; then
  echo "PHP 8.1 or newer is required." >&2
  exit 1
fi

PHP_VERSION_ID="$(php -r 'echo PHP_VERSION_ID;')"
if [[ "$PHP_VERSION_ID" -lt 80100 ]]; then
  echo "PHP 8.1 or newer is required; found $(php -r 'echo PHP_VERSION;')." >&2
  exit 1
fi

mkdir -p data/sources
if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "Created .env from .env.example"
fi

touch data/.gitkeep data/sources/.gitkeep
chmod 770 data data/sources 2>/dev/null || true
chmod 660 .env 2>/dev/null || true

bash scripts/check.sh

echo
echo "Setup complete. Start the app with: bash scripts/start.sh"
