#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

echo "== PHP syntax =="
while IFS= read -r -d '' file; do
  php -l "$file" >/dev/null
  echo "OK  $file"
done < <(find public src tests -type f -name '*.php' -print0 | sort -z)

if command -v node >/dev/null 2>&1; then
  echo "== JavaScript syntax =="
  node --check public/assets/app.js
else
  echo "SKIP JavaScript syntax (node is not installed)"
fi

echo "== Behaviour checks =="
php tests/run.php
