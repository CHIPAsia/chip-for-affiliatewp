# Build a distribution zip for WordPress.org submission / manual installs.
#
# The archive carries a single top-level directory named after the plugin.
# That is what lets WordPress recognise the upload as one plugin: given a zip
# whose entries sit at the root, the upgrader copies them straight into
# wp-content/plugins/ and the plugin cannot be activated.
#
# Excludes development-only files. Usage:
#   bash scripts/build-dist.sh           # -> dist/chip-for-affiliatewp.<ver>.zip
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(grep -oP "Version: \K[0-9.]+" chip-for-affiliatewp.php | head -1)
mkdir -p dist
rm -f "dist/chip-for-affiliatewp.$VERSION.zip" "dist/chip-for-affiliatewp.zip"

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/chip-for-affiliatewp"

cp chip-for-affiliatewp.php uninstall.php readme.txt LICENSE "$STAGE/chip-for-affiliatewp/"
cp -r includes languages assets "$STAGE/chip-for-affiliatewp/"

find "$STAGE" -name '.DS_Store' -delete

( cd "$STAGE" && zip -rq "$OLDPWD/dist/chip-for-affiliatewp.$VERSION.zip" chip-for-affiliatewp )

# A copy under a version-less name, so
# /releases/latest/download/chip-for-affiliatewp.zip always resolves to the
# newest release without the README having to name a version.
cp "dist/chip-for-affiliatewp.$VERSION.zip" "dist/chip-for-affiliatewp.zip"

echo "dist/chip-for-affiliatewp.$VERSION.zip"
echo "dist/chip-for-affiliatewp.zip"
unzip -l "dist/chip-for-affiliatewp.zip" | tail -3
