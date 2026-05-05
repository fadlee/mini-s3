#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="v0.0.0-test"
ZIP_PATH="$ROOT/dist/mini-s3-$VERSION.zip"
ZIP_LIST=""

fail() {
  printf '[FAIL] %s\n' "$1" >&2
  exit 1
}

assert_zip_contains() {
  local path="$1"
  if ! grep -Fq "mini-s3-$VERSION/$path" <<< "$ZIP_LIST"; then
    fail "zip should contain $path"
  fi
}

assert_zip_not_contains_prefix() {
  local path="$1"
  if grep -Fq "mini-s3-$VERSION/$path" <<< "$ZIP_LIST"; then
    fail "zip should not contain $path"
  fi
}

assert_zip_not_contains_exact() {
  local path="$1"
  if grep -Eq "[[:space:]]mini-s3-$VERSION/$path$" <<< "$ZIP_LIST"; then
    fail "zip should not contain $path"
  fi
}

assert_zip_file_count() {
  local expected_count="$1"
  local actual_count
  actual_count="$(unzip -Z1 "$ZIP_PATH" | grep -Ev '/$' | wc -l | tr -d '[:space:]')"
  if [ "$actual_count" != "$expected_count" ]; then
    fail "zip should contain exactly $expected_count file(s), found $actual_count"
  fi
}

rm -f "$ZIP_PATH"
"$ROOT/scripts/build-release.sh" "$VERSION"

[ -f "$ZIP_PATH" ] || fail "zip file was not created"
ZIP_LIST="$(unzip -l "$ZIP_PATH")"

assert_zip_contains "index.php"
assert_zip_contains ".htaccess"

assert_zip_not_contains_exact "README.md"
assert_zip_not_contains_exact "LICENSE"
assert_zip_not_contains_exact "config.example.php"
assert_zip_not_contains_exact "composer.json"
assert_zip_not_contains_prefix "public/"
assert_zip_not_contains_prefix "src/"
assert_zip_not_contains_prefix "tests/"
assert_zip_not_contains_prefix "docs/"
assert_zip_not_contains_prefix ".github/"
assert_zip_not_contains_prefix "data/"
assert_zip_not_contains_prefix "config/"
assert_zip_not_contains_prefix ".env"
assert_zip_not_contains_prefix "dist/"
assert_zip_not_contains_prefix "vendor/"
assert_zip_not_contains_prefix "composer.lock"

assert_zip_file_count 2

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
unzip -q "$ZIP_PATH" -d "$TMP_DIR"
php -l "$TMP_DIR/mini-s3-$VERSION/index.php" >/dev/null
if ! grep -Fq "define('MINI_S3_VERSION', '$VERSION');" "$TMP_DIR/mini-s3-$VERSION/index.php"; then
  fail "generated index.php should define MINI_S3_VERSION"
fi

printf '[PASS] Release archive test passed\n'
