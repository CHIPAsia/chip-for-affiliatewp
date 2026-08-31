# Build a distribution zip for WordPress.org submission / manual installs.
# Excludes development-only files. Usage:
#   bash scripts/build-dist.sh           # -> dist/chip-for-affiliatewp.<ver>.zip
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(grep -oP "Version: \K[0-9.]+" chip-for-affiliatewp.php | head -1)
mkdir -p dist
rm -f "dist/chip-for-affiliatewp.$VERSION.zip"

zip -rq "dist/chip-for-affiliatewp.$VERSION.zip" \
  chip-for-affiliatewp.php \
  uninstall.php \
  readme.txt \
  includes \
  languages \
  \
  -x "*.DS_Store"

echo "dist/chip-for-affiliatewp.$VERSION.zip"
unzip -l "dist/chip-for-affiliatewp.$VERSION.zip" | tail -3