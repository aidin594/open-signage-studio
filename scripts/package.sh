#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
VERSION=$(sed -n 's/^ \* Version: //p' open-signage-studio.php | head -1)
mkdir -p dist/open-signage-studio
rm -rf dist/open-signage-studio
mkdir -p dist/open-signage-studio
cp -R assets includes dist/open-signage-studio/
cp open-signage-studio.php readme.txt LICENSE dist/open-signage-studio/
(cd dist && zip -q -r "open-signage-studio-${VERSION}.zip" open-signage-studio)
rm -rf dist/open-signage-studio
printf 'Created dist/open-signage-studio-%s.zip\n' "$VERSION"
