# Build a distribution zip for WordPress.org submission / manual installs.
#
# The archive carries a single top-level directory named after the plugin.
# That is what lets WordPress recognise the upload as one plugin: given a zip
# whose entries sit at the root, the upgrader copies them straight into
# wp-content/plugins/ and the plugin cannot be activated.
#
# The file name carries no version, so the README's download link
# (/releases/latest/download/chip-for-affiliatewp.zip) always resolves to the
# newest release without naming one. The released version is in the plugin
# header inside the zip, and the release tag names it.
#
# Excludes development-only files. Usage:
#   bash scripts/build-dist.sh           # -> dist/chip-for-affiliatewp.zip
set -euo pipefail
cd "$(dirname "$0")/.."

mkdir -p dist
rm -f dist/chip-for-affiliatewp.zip

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/chip-for-affiliatewp"

cp chip-for-affiliatewp.php uninstall.php readme.txt LICENSE "$STAGE/chip-for-affiliatewp/"
cp -r includes languages assets "$STAGE/chip-for-affiliatewp/"

find "$STAGE" -name '.DS_Store' -delete

( cd "$STAGE" && zip -rq "$OLDPWD/dist/chip-for-affiliatewp.zip" chip-for-affiliatewp )

echo "dist/chip-for-affiliatewp.zip"
unzip -l dist/chip-for-affiliatewp.zip | tail -3
