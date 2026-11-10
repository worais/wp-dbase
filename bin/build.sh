#!/usr/bin/env bash
# Build the distributable plugin into build/wp-dbase (and build/wp-dbase.zip).
#
# Everything marked `export-ignore` in .gitattributes stays out, so the zip is
# exactly what `git archive` would ship. Works on a dirty tree too (local checks).
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
out="$root/build/wp-dbase"

mkdir -p "$out"
rm -f "$root/build/wp-dbase.zip"

excludes=(--exclude=.git --exclude=vendor --exclude=node_modules --exclude=build
	--exclude=.phpunit.cache --exclude=.phpcs-cache --exclude='infection.*')
while read -r path attr; do
	[[ "$attr" == "export-ignore" ]] && excludes+=("--exclude=${path#/}")
done < <(grep -E '^/[^ ]+[[:space:]]+export-ignore' "$root/.gitattributes")

# --delete keeps the directory itself (it may be bind-mounted into a test container).
rsync -a --delete "${excludes[@]}" "$root/" "$out/"

(cd "$root/build" && zip -qr wp-dbase.zip wp-dbase)
echo "Built $out and build/wp-dbase.zip"
