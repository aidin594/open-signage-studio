#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
for command in php node python3; do command -v "$command" >/dev/null || { echo "Missing: $command" >&2; exit 1; }; done
find . -type f -name '*.php' -print0 | while IFS= read -r -d '' f; do php -l "$f" >/dev/null; echo "PHP OK: $f"; done
find assets -type f -name '*.js' -print0 | while IFS= read -r -d '' f; do node --check "$f"; echo "JavaScript OK: $f"; done
python3 tests/source_hygiene.py
python3 tests/structure.py
php tests/smoke.php
php tests/signal.php
node tests/player-signal.cjs
