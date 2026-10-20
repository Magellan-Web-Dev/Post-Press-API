#!/usr/bin/env bash
#
# Builds an installable WordPress zip of this plugin.
#
# Usage: ./build.sh
#
# Output: build/post-press-api-<version>.zip, containing a single top-level
# post-press-api/ folder so WordPress installs it under the canonical name.
# The version is read from the plugin header and must match PPAPI_VERSION.
# Rebuilding the same version overwrites the existing zip.

set -euo pipefail

SLUG="post-press-api"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MAIN_FILE="$ROOT/$SLUG.php"
BUILD_DIR="$ROOT/build"

# Paths left out of the release package (rsync patterns; a leading / anchors to the plugin root).
EXCLUDES=(
    ".git"
    ".gitignore"
    ".gitattributes"
    ".github"
    ".DS_Store"
    "/tests"
    "/build"
    "/build.sh"
)

header_version="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "$MAIN_FILE" | head -n 1)"
constant_version="$(sed -n "s/.*define('PPAPI_VERSION',[[:space:]]*'\([^']*\)').*/\1/p" "$MAIN_FILE" | head -n 1)"

if [[ -z "$header_version" ]]; then
    echo "Error: could not read the Version header from $SLUG.php." >&2
    exit 1
fi

if [[ "$header_version" != "$constant_version" ]]; then
    echo "Error: version mismatch in $SLUG.php: header is '$header_version' but PPAPI_VERSION is '$constant_version'." >&2
    exit 1
fi

ZIP_PATH="$BUILD_DIR/$SLUG-$header_version.zip"

staging="$(mktemp -d)"
trap 'rm -rf "$staging"' EXIT

exclude_args=()
for pattern in "${EXCLUDES[@]}"; do
    exclude_args+=("--exclude=$pattern")
done

rsync -a "${exclude_args[@]}" "$ROOT/" "$staging/$SLUG/"

mkdir -p "$BUILD_DIR"
rm -f "$ZIP_PATH"
(cd "$staging" && zip -rqX "$ZIP_PATH" "$SLUG")

echo "Built $ZIP_PATH"
