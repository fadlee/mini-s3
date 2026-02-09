#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-/Users/fadlee/Library/Application Support/Herd/bin/php82}"
if [ ! -x "$PHP_BIN" ]; then
  PHP_BIN="${PHP_BIN_FALLBACK:-php}"
fi

SIGV4_HELPER="$ROOT/tests/integration/sigv4.php"
REQUEST_HELPER="$ROOT/tests/integration/request.php"
SIGN_HOST="mini-s3.test"
SIGN_BASE_URL="http://${SIGN_HOST}"

ACCESS_KEY="${AWS_ACCESS_KEY_ID:-minioadmin}"
SECRET_KEY="${AWS_SECRET_ACCESS_KEY:-minioadmin}"

TMP_DIR="$(mktemp -d /tmp/mini-s3-int-XXXXXX)"
TEST_BUCKET="itest-$(date +%s)-$RANDOM"
TEST_KEY="hello.txt"

cleanup() {
  rm -rf "$TMP_DIR"
  rm -rf "$ROOT/data/$TEST_BUCKET" >/dev/null 2>&1 || true
  rm -rf "$ROOT/data/.multipart/$TEST_BUCKET" >/dev/null 2>&1 || true
  rmdir "$ROOT/data/.multipart" >/dev/null 2>&1 || true
}
trap cleanup EXIT

fail() {
  echo "[FAIL] $1"
  exit 1
}

assert_eq() {
  local expected="$1"
  local actual="$2"
  local message="$3"
  if [ "$expected" != "$actual" ]; then
    fail "$message (expected=$expected actual=$actual)"
  fi
}

assert_contains() {
  local needle="$1"
  local file="$2"
  local message="$3"
  if ! rg -F -q "$needle" "$file"; then
    fail "$message"
  fi
}

assert_not_contains() {
  local needle="$1"
  local file="$2"
  local message="$3"
  if rg -F -q "$needle" "$file"; then
    fail "$message"
  fi
}

hash_file() {
  local file="$1"
  shasum -a 256 "$file" | awk '{print $1}'
}

meta_status() {
  local meta_file="$1"
  "$PHP_BIN" -r '$j=json_decode(file_get_contents($argv[1]), true); echo (string)($j["status"] ?? "");' "$meta_file"
}

sign_headers() {
  local method="$1"
  local full_url="$2"
  local payload_hash="$3"
  local signed
  signed="$("$PHP_BIN" "$SIGV4_HELPER" auth "$method" "$full_url" "$ACCESS_KEY" "$SECRET_KEY" "$payload_hash")"

  local amz_date
  local authorization
  amz_date="$(printf '%s\n' "$signed" | sed -n 's/^x-amz-date=//p')"
  authorization="$(printf '%s\n' "$signed" | sed -n 's/^authorization=//p')"

  if [ -z "$amz_date" ] || [ -z "$authorization" ]; then
    fail "Failed to build signed headers"
  fi

  printf '%s\n%s\n' "$amz_date" "$authorization"
}

run_request() {
  local method="$1"
  local uri="$2"
  local body_file="$3"
  local out_body_file="$4"
  local out_meta_file="$5"
  shift 5

  local -a headers=("$@")
  if [ -n "$body_file" ]; then
    cat "$body_file" | "$PHP_BIN" "$REQUEST_HELPER" "$method" "$uri" "$out_meta_file" "${headers[@]}" > "$out_body_file"
  else
    "$PHP_BIN" "$REQUEST_HELPER" "$method" "$uri" "$out_meta_file" "${headers[@]}" > "$out_body_file"
  fi
}

signed_request() {
  local method="$1"
  local uri="$2"
  local body_file="$3"
  local out_body_file="$4"
  local out_meta_file="$5"
  shift 5

  local payload_hash="e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
  if [ -n "$body_file" ]; then
    payload_hash="$(hash_file "$body_file")"
  fi

  local signed
  signed="$(sign_headers "$method" "$SIGN_BASE_URL$uri" "$payload_hash")"
  local amz_date
  local authorization
  amz_date="$(printf '%s\n' "$signed" | sed -n '1p')"
  authorization="$(printf '%s\n' "$signed" | sed -n '2p')"

  local -a headers=(
    "Host: $SIGN_HOST"
    "x-amz-date: $amz_date"
    "x-amz-content-sha256: $payload_hash"
    "Authorization: $authorization"
  )

  if [ "$#" -gt 0 ]; then
    headers+=("$@")
  fi

  run_request "$method" "$uri" "$body_file" "$out_body_file" "$out_meta_file" "${headers[@]}"
}

echo "[INFO] Starting mini-s3 integration tests (CLI harness)"

# 1) Upload/list/get/delete success
printf 'hello integration test\n' > "$TMP_DIR/hello.txt"
signed_request PUT "/$TEST_BUCKET/$TEST_KEY" "$TMP_DIR/hello.txt" "$TMP_DIR/put.body" "$TMP_DIR/put.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/put.meta")" "PUT should succeed"

signed_request GET "/$TEST_BUCKET/" "" "$TMP_DIR/list.body" "$TMP_DIR/list.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/list.meta")" "List should succeed"
assert_contains "<Key>$TEST_KEY</Key>" "$TMP_DIR/list.body" "List should include uploaded object"

signed_request GET "/$TEST_BUCKET/$TEST_KEY" "" "$TMP_DIR/get.body" "$TMP_DIR/get.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/get.meta")" "GET should succeed"
if ! diff -q "$TMP_DIR/hello.txt" "$TMP_DIR/get.body" >/dev/null; then
  fail "Downloaded body differs from uploaded body"
fi

signed_request DELETE "/$TEST_BUCKET/$TEST_KEY" "" "$TMP_DIR/del.body" "$TMP_DIR/del.meta"
assert_eq "204" "$(meta_status "$TMP_DIR/del.meta")" "DELETE should succeed"

# Re-upload object for next tests
signed_request PUT "/$TEST_BUCKET/$TEST_KEY" "$TMP_DIR/hello.txt" "$TMP_DIR/put2.body" "$TMP_DIR/put2.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/put2.meta")" "PUT should succeed for subsequent tests"

# 2) Valid access key but invalid signature -> 401
payload_hash="$(hash_file "$TMP_DIR/hello.txt")"
signed_lines="$(sign_headers PUT "$SIGN_BASE_URL/$TEST_BUCKET/invalid-sig.txt" "$payload_hash")"
amz_date="$(printf '%s\n' "$signed_lines" | sed -n '1p')"
authorization="$(printf '%s\n' "$signed_lines" | sed -n '2p')"
authorization_bad="${authorization}0"
run_request PUT "/$TEST_BUCKET/invalid-sig.txt" "$TMP_DIR/hello.txt" "$TMP_DIR/invalidsig.body" "$TMP_DIR/invalidsig.meta" \
  "Host: $SIGN_HOST" \
  "x-amz-date: $amz_date" \
  "x-amz-content-sha256: $payload_hash" \
  "Authorization: $authorization_bad"
assert_eq "401" "$(meta_status "$TMP_DIR/invalidsig.meta")" "Invalid signature must be rejected"

# 3) Signed host must match request host even when x-forwarded-host is set
empty_payload_hash="e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855"
signed_lines_host="$(sign_headers GET "$SIGN_BASE_URL/$TEST_BUCKET/$TEST_KEY" "$empty_payload_hash")"
amz_date_host="$(printf '%s\n' "$signed_lines_host" | sed -n '1p')"
authorization_host="$(printf '%s\n' "$signed_lines_host" | sed -n '2p')"
run_request GET "/$TEST_BUCKET/$TEST_KEY" "" "$TMP_DIR/host-mismatch.body" "$TMP_DIR/host-mismatch.meta" \
  "Host: internal.local" \
  "x-forwarded-host: $SIGN_HOST" \
  "x-amz-date: $amz_date_host" \
  "x-amz-content-sha256: $empty_payload_hash" \
  "Authorization: $authorization_host"
assert_eq "401" "$(meta_status "$TMP_DIR/host-mismatch.meta")" "Host mismatch should be rejected even with x-forwarded-host"

# 4) Valid presigned URL -> success
presigned_valid="$("$PHP_BIN" "$SIGV4_HELPER" presign GET "$SIGN_BASE_URL/$TEST_BUCKET/$TEST_KEY" "$ACCESS_KEY" "$SECRET_KEY" 120 0)"
valid_uri="$("$PHP_BIN" -r '$u=parse_url($argv[1]); echo ($u["path"] ?? "/") . (isset($u["query"]) ? "?" . $u["query"] : "");' "$presigned_valid")"
valid_host="$("$PHP_BIN" -r '$u=parse_url($argv[1]); echo ($u["host"] ?? ""); if(isset($u["port"])) echo ":".$u["port"];' "$presigned_valid")"
run_request GET "$valid_uri" "" "$TMP_DIR/presign-valid.body" "$TMP_DIR/presign-valid.meta" "Host: $valid_host"
assert_eq "200" "$(meta_status "$TMP_DIR/presign-valid.meta")" "Valid presigned request should succeed"
if ! diff -q "$TMP_DIR/hello.txt" "$TMP_DIR/presign-valid.body" >/dev/null; then
  fail "Valid presigned response body mismatch"
fi

# 5) Expired presigned URL -> 401
presigned_expired="$("$PHP_BIN" "$SIGV4_HELPER" presign GET "$SIGN_BASE_URL/$TEST_BUCKET/$TEST_KEY" "$ACCESS_KEY" "$SECRET_KEY" 1 -3600)"
expired_uri="$("$PHP_BIN" -r '$u=parse_url($argv[1]); echo ($u["path"] ?? "/") . (isset($u["query"]) ? "?" . $u["query"] : "");' "$presigned_expired")"
expired_host="$("$PHP_BIN" -r '$u=parse_url($argv[1]); echo ($u["host"] ?? ""); if(isset($u["port"])) echo ":".$u["port"];' "$presigned_expired")"
run_request GET "$expired_uri" "" "$TMP_DIR/presign-expired.body" "$TMP_DIR/presign-expired.meta" "Host: $expired_host"
assert_eq "401" "$(meta_status "$TMP_DIR/presign-expired.meta")" "Expired presigned request should be rejected"

# 6) Invalid XML on POST delete -> 400 MalformedXML
signed_request POST "/$TEST_BUCKET/?delete" "$ROOT/tests/integration/fixtures/delete-invalid.xml" "$TMP_DIR/delete-invalid.body" "$TMP_DIR/delete-invalid.meta"
assert_eq "400" "$(meta_status "$TMP_DIR/delete-invalid.meta")" "Invalid XML delete request should fail"
assert_contains "MalformedXML" "$TMP_DIR/delete-invalid.body" "MalformedXML code should be returned"

# 7) Multipart: initiate/upload/complete success
printf 'part-one-' > "$TMP_DIR/part1.bin"
printf 'part-two' > "$TMP_DIR/part2.bin"

signed_request POST "/$TEST_BUCKET/multi.bin?uploads" "" "$TMP_DIR/mp-init.body" "$TMP_DIR/mp-init.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/mp-init.meta")" "Multipart init should succeed"
upload_id="$(sed -n 's:.*<UploadId>\([^<]*\)</UploadId>.*:\1:p' "$TMP_DIR/mp-init.body")"
if [ -z "$upload_id" ]; then
  fail "UploadId not found from multipart init"
fi

signed_request PUT "/$TEST_BUCKET/multi.bin?partNumber=1&uploadId=$upload_id" "$TMP_DIR/part1.bin" "$TMP_DIR/mp-put1.body" "$TMP_DIR/mp-put1.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/mp-put1.meta")" "Multipart part 1 upload should succeed"

signed_request PUT "/$TEST_BUCKET/multi.bin?partNumber=2&uploadId=$upload_id" "$TMP_DIR/part2.bin" "$TMP_DIR/mp-put2.body" "$TMP_DIR/mp-put2.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/mp-put2.meta")" "Multipart part 2 upload should succeed"

# Multipart temp files must not leak into bucket listing
signed_request GET "/$TEST_BUCKET/" "" "$TMP_DIR/mp-list.body" "$TMP_DIR/mp-list.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/mp-list.meta")" "List should succeed while multipart upload is in progress"
assert_not_contains "multi.bin-temp/" "$TMP_DIR/mp-list.body" "Multipart temp directory must not appear in list response"
assert_not_contains "$upload_id" "$TMP_DIR/mp-list.body" "Multipart upload id must not appear in list response"

cat > "$TMP_DIR/mp-complete.xml" <<XML
<CompleteMultipartUpload>
  <Part><PartNumber>1</PartNumber><ETag>"unused"</ETag></Part>
  <Part><PartNumber>2</PartNumber><ETag>"unused"</ETag></Part>
</CompleteMultipartUpload>
XML

signed_request POST "/$TEST_BUCKET/multi.bin?uploadId=$upload_id" "$TMP_DIR/mp-complete.xml" "$TMP_DIR/mp-complete.body" "$TMP_DIR/mp-complete.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/mp-complete.meta")" "Multipart complete should succeed"

signed_request GET "/$TEST_BUCKET/multi.bin" "" "$TMP_DIR/multi-get.body" "$TMP_DIR/multi-get.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/multi-get.meta")" "Multipart object GET should succeed"
printf 'part-one-part-two' > "$TMP_DIR/multi-expected.bin"
if ! diff -q "$TMP_DIR/multi-expected.bin" "$TMP_DIR/multi-get.body" >/dev/null; then
  fail "Multipart merged output mismatch"
fi

# 8) Completing upload A must not delete upload B temp session
signed_request POST "/$TEST_BUCKET/concurrent.bin?uploads" "" "$TMP_DIR/c-init-a.body" "$TMP_DIR/c-init-a.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/c-init-a.meta")" "Concurrent upload A init should succeed"
upload_a="$(sed -n 's:.*<UploadId>\([^<]*\)</UploadId>.*:\1:p' "$TMP_DIR/c-init-a.body")"

signed_request POST "/$TEST_BUCKET/concurrent.bin?uploads" "" "$TMP_DIR/c-init-b.body" "$TMP_DIR/c-init-b.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/c-init-b.meta")" "Concurrent upload B init should succeed"
upload_b="$(sed -n 's:.*<UploadId>\([^<]*\)</UploadId>.*:\1:p' "$TMP_DIR/c-init-b.body")"

printf 'A1' > "$TMP_DIR/c-a1.bin"
printf 'B1' > "$TMP_DIR/c-b1.bin"
printf 'B2' > "$TMP_DIR/c-b2.bin"

signed_request PUT "/$TEST_BUCKET/concurrent.bin?partNumber=1&uploadId=$upload_a" "$TMP_DIR/c-a1.bin" "$TMP_DIR/c-a1.body" "$TMP_DIR/c-a1.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/c-a1.meta")" "Concurrent upload A part1 should succeed"

signed_request PUT "/$TEST_BUCKET/concurrent.bin?partNumber=1&uploadId=$upload_b" "$TMP_DIR/c-b1.bin" "$TMP_DIR/c-b1.body" "$TMP_DIR/c-b1.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/c-b1.meta")" "Concurrent upload B part1 should succeed"

cat > "$TMP_DIR/c-complete-a.xml" <<XML
<CompleteMultipartUpload>
  <Part><PartNumber>1</PartNumber><ETag>"unused"</ETag></Part>
</CompleteMultipartUpload>
XML

signed_request POST "/$TEST_BUCKET/concurrent.bin?uploadId=$upload_a" "$TMP_DIR/c-complete-a.xml" "$TMP_DIR/c-complete-a.body" "$TMP_DIR/c-complete-a.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/c-complete-a.meta")" "Concurrent upload A complete should succeed"

signed_request PUT "/$TEST_BUCKET/concurrent.bin?partNumber=2&uploadId=$upload_b" "$TMP_DIR/c-b2.bin" "$TMP_DIR/c-b2.body" "$TMP_DIR/c-b2.meta"
assert_eq "200" "$(meta_status "$TMP_DIR/c-b2.meta")" "Upload B should still exist after upload A complete"

# 9) Request body > MAX_REQUEST_SIZE -> 413
printf 'x' > "$TMP_DIR/too-large.bin"
signed_request PUT "/$TEST_BUCKET/too-large.bin" "$TMP_DIR/too-large.bin" "$TMP_DIR/too-large.body" "$TMP_DIR/too-large.meta" "Content-Length: 104857601"
assert_eq "413" "$(meta_status "$TMP_DIR/too-large.meta")" "Oversized request should be rejected"

# 10) Range valid -> 206, range invalid -> 416
signed_request GET "/$TEST_BUCKET/multi.bin" "" "$TMP_DIR/range-valid.body" "$TMP_DIR/range-valid.meta" "Range: bytes=0-3"
assert_eq "206" "$(meta_status "$TMP_DIR/range-valid.meta")" "Valid range request should return 206"
range_valid_size="$(wc -c < "$TMP_DIR/range-valid.body" | tr -d ' ')"
assert_eq "4" "$range_valid_size" "Valid range response body length should be 4"

signed_request GET "/$TEST_BUCKET/multi.bin" "" "$TMP_DIR/range-invalid.body" "$TMP_DIR/range-invalid.meta" "Range: bytes=99999-100000"
assert_eq "416" "$(meta_status "$TMP_DIR/range-invalid.meta")" "Invalid range request should return 416"

echo "[PASS] All integration scenarios passed"
