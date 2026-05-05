#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-}"

if [ -z "$VERSION" ]; then
  printf 'Usage: scripts/build-release.sh <version>\n' >&2
  exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
  printf 'Error: zip command not found\n' >&2
  exit 1
fi

REQUIRED_PATHS=(
  "README.md"
  "config.example.php"
  "composer.json"
  "public"
  "src"
)

for path in "${REQUIRED_PATHS[@]}"; do
  if [ ! -e "$ROOT/$path" ]; then
    printf 'Error: required release path missing: %s\n' "$path" >&2
    exit 1
  fi
done

DIST_DIR="$ROOT/dist"
PACKAGE_NAME="mini-s3-$VERSION"
ZIP_PATH="$DIST_DIR/$PACKAGE_NAME.zip"
STAGE_PARENT="$DIST_DIR/.stage-$PACKAGE_NAME"
STAGE_DIR="$STAGE_PARENT/$PACKAGE_NAME"

rm -rf "$STAGE_PARENT"
mkdir -p "$STAGE_DIR" "$DIST_DIR"

cp "$ROOT/README.md" "$STAGE_DIR/README.md"
cp "$ROOT/config.example.php" "$STAGE_DIR/config.example.php"
cp "$ROOT/composer.json" "$STAGE_DIR/composer.json"
cp -R "$ROOT/public" "$STAGE_DIR/public"
cp -R "$ROOT/src" "$STAGE_DIR/src"

if [ -f "$ROOT/LICENSE" ]; then
  cp "$ROOT/LICENSE" "$STAGE_DIR/LICENSE"
fi

rm -f "$ZIP_PATH"
(
  cd "$STAGE_PARENT"
  zip -qr "$ZIP_PATH" "$PACKAGE_NAME"
)

rm -rf "$STAGE_PARENT"
printf 'Created %s\n' "$ZIP_PATH"
