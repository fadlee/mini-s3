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

rm -f "$ZIP_PATH"
"$ROOT/scripts/build-release.sh" "$VERSION"

[ -f "$ZIP_PATH" ] || fail "zip file was not created"
ZIP_LIST="$(unzip -l "$ZIP_PATH")"

assert_zip_contains "README.md"
assert_zip_contains "config.example.php"
assert_zip_contains "composer.json"
assert_zip_contains "public/.htaccess"
assert_zip_contains "public/index.php"
assert_zip_contains "src/S3/S3Router.php"

assert_zip_not_contains_exact ".htaccess"
assert_zip_not_contains_prefix "tests/"
assert_zip_not_contains_prefix "docs/superpowers/"
assert_zip_not_contains_prefix ".github/"
assert_zip_not_contains_prefix "data/"
assert_zip_not_contains_prefix "config/config.php"
assert_zip_not_contains_prefix ".env"
assert_zip_not_contains_prefix "dist/"
assert_zip_not_contains_prefix "vendor/"
assert_zip_not_contains_prefix "composer.lock"

printf '[PASS] Release archive test passed\n'
