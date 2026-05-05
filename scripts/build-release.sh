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
  "public/index.php"
  "public/.htaccess"
  "src"
)

for path in "${REQUIRED_PATHS[@]}"; do
  if [ ! -e "$ROOT/$path" ]; then
    printf 'Error: required release path missing: %s\n' "$path" >&2
    exit 1
  fi
done

SOURCE_FILES=(
  "src/Config/ConfigLoader.php"
  "src/Admin/AdminAuth.php"
  "src/Admin/AdminConfigWriter.php"
  "src/Admin/AdminStats.php"
  "src/Admin/AdminRenderer.php"
  "src/Admin/AdminRouter.php"
  "src/Auth/AuthException.php"
  "src/Auth/SigV4Authenticator.php"
  "src/Http/RequestContext.php"
  "src/Storage/FileStorage.php"
  "src/S3/S3Response.php"
  "src/S3/RequestValidator.php"
  "src/S3/S3Router.php"
)

for path in "${SOURCE_FILES[@]}"; do
  if [ ! -f "$ROOT/$path" ]; then
    printf 'Error: required source file missing: %s\n' "$path" >&2
    exit 1
  fi
done

append_php_body() {
  local file="$1"
  php -r '
    $path = $argv[1];
    $code = file_get_contents($path);
    if ($code === false) {
        fwrite(STDERR, "Error: unable to read $path\n");
        exit(1);
    }

    $code = preg_replace("/^<\\?php\\s*/", "", $code, 1);
    $code = preg_replace("/^declare\\(strict_types=1\\);\\s*/", "", $code, 1);
    $code = preg_replace("/\\?>\\s*$/", "", $code, 1);

    if (preg_match("/^namespace\\s+([^;]+);\\s*/", $code, $matches)) {
        $namespace = trim($matches[1]);
        $code = preg_replace("/^namespace\\s+[^;]+;\\s*/", "", $code, 1);
        echo "namespace $namespace {\n";
        echo rtrim($code) . "\n";
        echo "}\n\n";
        exit(0);
    }

    echo rtrim($code) . "\n\n";
  ' "$file"
}

DIST_DIR="$ROOT/dist"
PACKAGE_NAME="mini-s3-$VERSION"
ZIP_PATH="$DIST_DIR/$PACKAGE_NAME.zip"
STAGE_PARENT="$DIST_DIR/.stage-$PACKAGE_NAME"
STAGE_DIR="$STAGE_PARENT/$PACKAGE_NAME"

rm -rf "$STAGE_PARENT"
mkdir -p "$STAGE_DIR" "$DIST_DIR"

BUNDLE_PATH="$STAGE_DIR/index.php"

{
  printf '<?php\n\n'
  printf 'declare(strict_types=1);\n\n'
  printf "namespace {\n"
  printf "    define('BASE_PATH', __DIR__);\n"
  printf "}\n\n"

  for path in "${SOURCE_FILES[@]}"; do
    append_php_body "$ROOT/$path"
  done

  printf "namespace {\n"
  php -r '
    $path = $argv[1];
    $code = file_get_contents($path);
    if ($code === false) {
        fwrite(STDERR, "Error: unable to read $path\n");
        exit(1);
    }

    $code = preg_replace("/^<\\?php\\s*/", "", $code, 1);
    $code = preg_replace("/^declare\\(strict_types=1\\);\\s*/", "", $code, 1);
    $basePathLine = "define(" . chr(39) . "BASE_PATH" . chr(39) . ", realpath(__DIR__." . chr(39) . "/.." . chr(39) . "));";
    $code = str_replace($basePathLine . "\n\n", "", $code);
    $code = str_replace($basePathLine . "\n", "", $code);
    $lines = explode("\n", $code);
    $keptLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, "require_once BASE_PATH . ") && str_contains($trimmed, "/src/")) {
            continue;
        }
        $keptLines[] = $line;
    }
    $code = implode("\n", $keptLines);
    $code = trim($code);

    $lines = explode("\n", $code);
    foreach ($lines as $line) {
        echo "    " . rtrim($line) . "\n";
    }
  ' "$ROOT/public/index.php"
  printf "}\n"
} > "$BUNDLE_PATH"

php -l "$BUNDLE_PATH" >/dev/null
cp "$ROOT/public/.htaccess" "$STAGE_DIR/.htaccess"

rm -f "$ZIP_PATH"
(
  cd "$STAGE_PARENT"
  zip -qr "$ZIP_PATH" "$PACKAGE_NAME"
)

rm -rf "$STAGE_PARENT"
printf 'Created %s\n' "$ZIP_PATH"
