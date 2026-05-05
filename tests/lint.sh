#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

files=(
  "$ROOT/config.example.php"
  "$ROOT/public/index.php"
  "$ROOT/src/Admin/AdminAuth.php"
  "$ROOT/src/Admin/AdminConfigWriter.php"
  "$ROOT/src/Admin/AdminRenderer.php"
  "$ROOT/src/Admin/AdminRouter.php"
  "$ROOT/src/Admin/AdminStats.php"
  "$ROOT/src/Auth/AuthException.php"
  "$ROOT/src/Auth/SigV4Authenticator.php"
  "$ROOT/src/Config/ConfigLoader.php"
  "$ROOT/src/Http/RequestContext.php"
  "$ROOT/src/S3/S3Response.php"
  "$ROOT/src/S3/RequestValidator.php"
  "$ROOT/src/S3/S3Router.php"
  "$ROOT/src/Storage/FileStorage.php"
  "$ROOT/tests/integration/request.php"
  "$ROOT/tests/integration/sigv4.php"
  "$ROOT/tests/unit/admin-auth.php"
  "$ROOT/tests/unit/admin-config-writer.php"
  "$ROOT/tests/unit/admin-renderer.php"
  "$ROOT/tests/unit/admin-stats.php"
  "$ROOT/tests/unit/config-loader.php"
  "$ROOT/tests/unit/request-validator.php"
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$file"
done
