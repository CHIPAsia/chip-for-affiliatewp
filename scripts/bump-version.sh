#!/usr/bin/env bash
# Bump the plugin version across chip-for-affiliatewp.php and readme.txt.
# Usage: ./scripts/bump-version.sh X.Y.Z
set -euo pipefail
cd "$(dirname "$0")/.."

[[ $# -eq 1 ]] || { echo "usage: $0 X.Y.Z"; exit 1; }
NEW="$1"
[[ "$NEW" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "invalid version: $NEW (want X.Y.Z)"; exit 1; }

OLD=$(grep -oP "Version: \K[0-9.]+" chip-for-affiliatewp.php | head -1)
[ -n "$OLD" ] || { echo "could not read current version"; exit 1; }

sed -i "s/ \* Version: ${OLD}/ * Version: ${NEW}/" chip-for-affiliatewp.php
sed -i "s/define( 'CHIP_AFFILIATEWP_VERSION', '${OLD}' );/define( 'CHIP_AFFILIATEWP_VERSION', '${NEW}' );/" chip-for-affiliatewp.php
sed -i "s/^Stable tag: ${OLD}$/Stable tag: ${NEW}/" readme.txt

git add chip-for-affiliatewp.php readme.txt
git commit -m "chore: bump version ${OLD} -> ${NEW}"
echo "bumped ${OLD} -> ${NEW}"