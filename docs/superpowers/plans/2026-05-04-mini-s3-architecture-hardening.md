# Mini S3 Architecture Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Harden Mini S3 deployment/config/tooling and reduce router/storage coupling while preserving existing S3 behavior.

**Architecture:** Keep runtime dependency-free with manual `require_once`. Add dev-only Composer scripts, hybrid config loading, focused validation helper, storage metadata/stream methods, and response header helpers. Refactor only boundaries touched by current risks; avoid framework/controller rewrite.

**Tech Stack:** PHP 8.0+, shell scripts, optional Composer scripts, Apache/Nginx docs, filesystem storage.

---

## File Map

- Create: `.gitignore` to ignore local secrets/config/data artifacts.
- Create: `composer.json` for dev-only scripts.
- Create: `tests/lint.sh` for portable PHP syntax linting.
- Create: `tests/unit/config-loader.php` for focused config tests.
- Create: `tests/unit/request-validator.php` for focused validator tests.
- Create: `src/S3/RequestValidator.php` for bucket/key/range/request-size parsing and validation.
- Modify: `config.example.php` to safe defaults and env docs comments.
- Modify: `config/config.php` only if still tracked during migration; remove from git index in commit plan, do not delete user local file.
- Modify: `src/Config/ConfigLoader.php` to merge defaults, config file, then env overrides.
- Modify: `public/index.php` to require new validator file and pass validator to router.
- Modify: `src/S3/S3Router.php` to use `RequestValidator`, storage metadata/stream, and response helper.
- Modify: `src/S3/S3Response.php` to add object header helper methods.
- Modify: `src/Storage/FileStorage.php` to add metadata and stream methods.
- Modify: `tests/integration/run.sh` to default `PHP_BIN` to `php`.
- Modify: `README.md` to document `public/` web root, safe data/config, env config, and Composer scripts.

## Task 1: Add Dev Tooling Skeleton

**Files:**
- Create: `composer.json`
- Create: `tests/lint.sh`
- Modify: `tests/integration/run.sh:5-8`

- [ ] **Step 1: Create lint script**

Create `tests/lint.sh`:

```bash
#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

files=(
  "$ROOT/config.example.php"
  "$ROOT/public/index.php"
  "$ROOT/src/Auth/AuthException.php"
  "$ROOT/src/Auth/SigV4Authenticator.php"
  "$ROOT/src/Config/ConfigLoader.php"
  "$ROOT/src/Http/RequestContext.php"
  "$ROOT/src/S3/S3Response.php"
  "$ROOT/src/S3/S3Router.php"
  "$ROOT/src/Storage/FileStorage.php"
  "$ROOT/tests/integration/request.php"
  "$ROOT/tests/integration/sigv4.php"
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$file"
done
```

- [ ] **Step 2: Make lint script executable**

Run:

```bash
chmod +x tests/lint.sh
```

Expected: no output.

- [ ] **Step 3: Add Composer scripts**

Create `composer.json`:

```json
{
  "name": "mini-s3/server",
  "description": "Lightweight S3-compatible object storage server in PHP.",
  "type": "project",
  "license": "MIT",
  "scripts": {
    "lint": "tests/lint.sh",
    "test": "tests/integration/run.sh",
    "check": [
      "@lint",
      "@test"
    ]
  },
  "require": {},
  "require-dev": {}
}
```

- [ ] **Step 4: Make integration PHP default portable**

In `tests/integration/run.sh`, replace lines 5-8:

```bash
PHP_BIN="${PHP_BIN:-/Users/fadlee/Library/Application Support/Herd/bin/php82}"
if [ ! -x "$PHP_BIN" ]; then
  PHP_BIN="${PHP_BIN_FALLBACK:-php}"
fi
```

with:

```bash
PHP_BIN="${PHP_BIN:-php}"
```

- [ ] **Step 5: Run lint**

Run:

```bash
tests/lint.sh
```

Expected: each PHP file reports `No syntax errors detected`.

- [ ] **Step 6: Commit tooling changes**

Only commit if user explicitly requested commits. Otherwise skip commit and leave changes staged/unstaged as-is.

Commit command if requested:

```bash
git add composer.json tests/lint.sh tests/integration/run.sh
git commit -m "chore: add portable dev tooling"
```

## Task 2: Add Git Ignore and Safe Example Config

**Files:**
- Create: `.gitignore`
- Modify: `config.example.php`
- Git index: remove tracked `config/config.php` without deleting local file

- [ ] **Step 1: Add `.gitignore`**

Create `.gitignore`:

```gitignore
.DS_Store
.env
/data/
/config/config.php
```

- [ ] **Step 2: Update safe example config**

Update `config.example.php` to:

```php
<?php

return [
    'DATA_DIR' => __DIR__ . '/data',
    'MAX_REQUEST_SIZE' => 100 * 1024 * 1024,
    'CREDENTIALS' => [
        'replace-with-access-key' => 'replace-with-secret-key',
    ],
    'ALLOW_LEGACY_ACCESS_KEY_ONLY' => false,
    'ALLOWED_ACCESS_KEYS' => [],
    'CLOCK_SKEW_SECONDS' => 900,
    'MAX_PRESIGN_EXPIRES' => 604800,
    'AUTH_DEBUG_LOG' => '',
    'ALLOW_HOST_CANDIDATE_FALLBACKS' => false,
    'PUBLIC_READ_ALL_BUCKETS' => false,
];
```

- [ ] **Step 3: Remove local runtime config from git index**

Run:

```bash
git rm --cached config/config.php
```

Expected: `config/config.php` remains on disk but is scheduled for deletion from repository tracking.

If file is already untracked, expected output may be fatal `pathspec` not matched; verify with `git status --short` and continue if `config/config.php` is not tracked.

- [ ] **Step 4: Run lint**

Run:

```bash
tests/lint.sh
```

Expected: PASS.

- [ ] **Step 5: Commit ignore/config changes**

Only commit if user explicitly requested commits.

```bash
git add .gitignore config.example.php
git add -u config/config.php
git commit -m "chore: stop tracking local runtime config"
```

## Task 3: Add Hybrid Config Loading Tests

**Files:**
- Create: `tests/unit/config-loader.php`

- [ ] **Step 1: Write focused config tests**

Create `tests/unit/config-loader.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/ConfigLoader.php';

use MiniS3\Config\ConfigLoader;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

function assertThrows(callable $callback, string $message): void
{
    global $failures;
    try {
        $callback();
    } catch (Throwable $e) {
        return;
    }

    $failures++;
    fwrite(STDERR, "[FAIL] {$message}: expected exception" . PHP_EOL);
}

function withEnv(array $env, callable $callback): void
{
    $keys = array_keys($env);
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key . '=' . $env[$key]);
        $_ENV[$key] = $env[$key];
    }

    try {
        $callback();
    } finally {
        foreach ($keys as $key) {
            if ($previous[$key] === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $previous[$key]);
                $_ENV[$key] = $previous[$key];
            }
        }
    }
}

function tempProject(array $config): string
{
    $base = sys_get_temp_dir() . '/mini-s3-config-' . bin2hex(random_bytes(4));
    mkdir($base . '/config', 0777, true);
    if ($config !== []) {
        file_put_contents($base . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
    }

    return $base;
}

$base = tempProject([
    'CREDENTIALS' => ['file-key' => 'file-secret'],
    'PUBLIC_READ_ALL_BUCKETS' => true,
]);
$config = ConfigLoader::load($base);
assertSameValue(['file-key' => 'file-secret'], $config['CREDENTIALS'], 'config file credentials load');
assertSameValue(true, $config['PUBLIC_READ_ALL_BUCKETS'], 'config file public read loads');

withEnv([
    'MINI_S3_CREDENTIALS_JSON' => '{"env-key":"env-secret"}',
    'MINI_S3_PUBLIC_READ_ALL_BUCKETS' => 'false',
], function () use ($base): void {
    $config = ConfigLoader::load($base);
    assertSameValue(['env-key' => 'env-secret'], $config['CREDENTIALS'], 'env credentials override file');
    assertSameValue(false, $config['PUBLIC_READ_ALL_BUCKETS'], 'env boolean override file');
});

withEnv(['MINI_S3_CREDENTIALS_JSON' => '{bad-json'], function () use ($base): void {
    assertThrows(fn() => ConfigLoader::load($base), 'invalid credential JSON fails');
});

$emptyBase = tempProject([]);
assertThrows(fn() => ConfigLoader::load($emptyBase), 'empty credentials fail closed');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] ConfigLoader tests passed" . PHP_EOL;
```

- [ ] **Step 2: Run test and verify failure**

Run:

```bash
php tests/unit/config-loader.php
```

Expected: FAIL because env overrides are not implemented yet.

## Task 4: Implement Hybrid Config Loading

**Files:**
- Modify: `src/Config/ConfigLoader.php`
- Test: `tests/unit/config-loader.php`

- [ ] **Step 1: Add env override implementation**

In `src/Config/ConfigLoader.php`, add env overrides before final normalization. Add private helpers below `load()`.

Expected implementation shape:

```php
        $config = self::applyEnvironmentOverrides($config);
```

Place it after config-file/legacy merge and before current normalization at line 59.

Add helpers:

```php
    private static function applyEnvironmentOverrides(array $config): array
    {
        $stringMap = [
            'MINI_S3_DATA_DIR' => 'DATA_DIR',
            'MINI_S3_AUTH_DEBUG_LOG' => 'AUTH_DEBUG_LOG',
        ];

        foreach ($stringMap as $envName => $configKey) {
            $value = self::env($envName);
            if ($value !== null) {
                $config[$configKey] = $value;
            }
        }

        $maxRequestSize = self::env('MINI_S3_MAX_REQUEST_SIZE');
        if ($maxRequestSize !== null) {
            $config['MAX_REQUEST_SIZE'] = (int) $maxRequestSize;
        }

        $publicRead = self::env('MINI_S3_PUBLIC_READ_ALL_BUCKETS');
        if ($publicRead !== null) {
            $config['PUBLIC_READ_ALL_BUCKETS'] = self::parseBoolean($publicRead);
        }

        $hostFallbacks = self::env('MINI_S3_ALLOW_HOST_CANDIDATE_FALLBACKS');
        if ($hostFallbacks !== null) {
            $config['ALLOW_HOST_CANDIDATE_FALLBACKS'] = self::parseBoolean($hostFallbacks);
        }

        $credentialsJson = self::env('MINI_S3_CREDENTIALS_JSON');
        if ($credentialsJson !== null) {
            $decoded = json_decode($credentialsJson, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Misconfiguration: MINI_S3_CREDENTIALS_JSON must be a JSON object');
            }
            $config['CREDENTIALS'] = $decoded;
        }

        return $config;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }

    private static function parseBoolean(string $value): bool
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
```

- [ ] **Step 2: Run focused config test**

Run:

```bash
php tests/unit/config-loader.php
```

Expected: `[PASS] ConfigLoader tests passed`.

- [ ] **Step 3: Add config test to lint script file list**

In `tests/lint.sh`, add:

```bash
  "$ROOT/tests/unit/config-loader.php"
```

- [ ] **Step 4: Run lint**

Run:

```bash
tests/lint.sh
```

Expected: PASS.

- [ ] **Step 5: Commit config loader changes**

Only commit if user explicitly requested commits.

```bash
git add src/Config/ConfigLoader.php tests/unit/config-loader.php tests/lint.sh
git commit -m "feat: support hybrid configuration loading"
```

## Task 5: Add Request Validator Tests

**Files:**
- Create: `tests/unit/request-validator.php`
- Future create: `src/S3/RequestValidator.php`

- [ ] **Step 1: Write validator tests**

Create `tests/unit/request-validator.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/S3/RequestValidator.php';

use MiniS3\S3\RequestValidator;

$validator = new RequestValidator();
$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}" . PHP_EOL);
    }
}

check($validator->isValidBucketName('valid-bucket'), 'valid bucket accepted');
check(!$validator->isValidBucketName('ab'), 'short bucket rejected');
check(!$validator->isValidBucketName('Invalid'), 'uppercase bucket rejected');
check(!$validator->isValidBucketName('192.168.0.1'), 'ip-looking bucket rejected');

check($validator->isValidObjectKey('path/file.txt'), 'normal object key accepted');
check(!$validator->isValidObjectKey("bad\0key"), 'NUL object key rejected');
check(!$validator->isValidObjectKey('../secret'), 'parent segment rejected');
check(!$validator->isValidObjectKey('./secret'), 'dot segment rejected');

check($validator->isPositiveInteger('1'), 'positive integer accepted');
check(!$validator->isPositiveInteger('0'), 'zero rejected');
check(!$validator->isPositiveInteger('abc'), 'non-digit rejected');

[$valid, $start, $end] = $validator->parseRange('bytes=0-3', 10);
check($valid && $start === 0 && $end === 3, 'normal range parsed');

[$valid, $start, $end] = $validator->parseRange('bytes=-4', 10);
check($valid && $start === 6 && $end === 9, 'suffix range parsed');

[$valid] = $validator->parseRange('bytes=999-1000', 10);
check(!$valid, 'out of range rejected');

[$valid] = $validator->parseRange('items=0-1', 10);
check(!$valid, 'wrong unit rejected');

check($validator->isOversizedRequest('104857601', 104857600), 'oversized request detected');
check(!$validator->isOversizedRequest('104857600', 104857600), 'max-sized request accepted');
check(!$validator->isOversizedRequest('abc', 104857600), 'invalid content length ignored like current behavior');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] RequestValidator tests passed" . PHP_EOL;
```

- [ ] **Step 2: Run test and verify failure**

Run:

```bash
php tests/unit/request-validator.php
```

Expected: FAIL because `src/S3/RequestValidator.php` does not exist yet.

## Task 6: Implement Request Validator and Wire Router

**Files:**
- Create: `src/S3/RequestValidator.php`
- Modify: `public/index.php`
- Modify: `src/S3/S3Router.php`
- Modify: `tests/lint.sh`
- Test: `tests/unit/request-validator.php`

- [ ] **Step 1: Create validator implementation**

Create `src/S3/RequestValidator.php`:

```php
<?php

declare(strict_types=1);

namespace MiniS3\S3;

final class RequestValidator
{
    public function isValidBucketName(string $bucket): bool
    {
        $length = strlen($bucket);
        if ($length < 3 || $length > 63) {
            return false;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $bucket)) {
            return false;
        }

        if (str_contains($bucket, '..') || str_contains($bucket, '.-') || str_contains($bucket, '-.')) {
            return false;
        }

        if (filter_var($bucket, FILTER_VALIDATE_IP)) {
            return false;
        }

        return true;
    }

    public function isValidObjectKey(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        if (str_contains($key, "\0")) {
            return false;
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public function isPositiveInteger(string $value): bool
    {
        return ctype_digit($value) && (int) $value > 0;
    }

    public function isOversizedRequest(?string $contentLength, int $maxRequestSize): bool
    {
        if ($contentLength === null || $contentLength === '' || !ctype_digit($contentLength)) {
            return false;
        }

        return (int) $contentLength > $maxRequestSize;
    }

    public function parseRange(string $range, int $fileSize): array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches)) {
            return [false, 0, 0];
        }

        if ($fileSize <= 0) {
            return [false, 0, 0];
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return [false, 0, 0];
        }

        if ($startRaw === '') {
            if (!ctype_digit($endRaw)) {
                return [false, 0, 0];
            }

            $suffixLength = (int) $endRaw;
            if ($suffixLength <= 0) {
                return [false, 0, 0];
            }

            return [true, max(0, $fileSize - $suffixLength), $fileSize - 1];
        }

        if (!ctype_digit($startRaw)) {
            return [false, 0, 0];
        }

        $start = (int) $startRaw;
        if ($start >= $fileSize) {
            return [false, 0, 0];
        }

        if ($endRaw === '') {
            return [true, $start, $fileSize - 1];
        }

        if (!ctype_digit($endRaw)) {
            return [false, 0, 0];
        }

        $end = min((int) $endRaw, $fileSize - 1);
        if ($start > $end) {
            return [false, 0, 0];
        }

        return [true, $start, $end];
    }
}
```

- [ ] **Step 2: Wire validator into entrypoint**

In `public/index.php`, add require:

```php
require_once BASE_PATH . '/src/S3/RequestValidator.php';
```

Add use:

```php
use MiniS3\S3\RequestValidator;
```

Create before router:

```php
    $validator = new RequestValidator();
```

Pass it to `S3Router` constructor after `$response`:

```php
        $validator,
```

- [ ] **Step 3: Update router constructor and calls**

In `src/S3/S3Router.php`, add constructor property after `$response`:

```php
        private readonly RequestValidator $validator,
```

Replace internal calls:

```php
$this->isPositiveInteger($partNumber)
```

with:

```php
$this->validator->isPositiveInteger($partNumber)
```

Replace:

```php
$this->isValidObjectKey($objectKey)
```

with:

```php
$this->validator->isValidObjectKey($objectKey)
```

Replace bucket/key validation internals with validator calls:

```php
        if ($bucket !== '' && !$this->validator->isValidBucketName($bucket)) {
            $this->response->error(400, 'InvalidBucketName', 'Invalid bucket name', '/' . $bucket);
        }

        if ($key !== '' && !$this->validator->isValidObjectKey($key)) {
            $this->response->error(400, 'InvalidObjectKey', 'Invalid object key', $this->resource($bucket, $key));
        }
```

Replace `validateRequestSize()` body with:

```php
        if ($this->validator->isOversizedRequest($this->request->getHeader('content-length'), $this->maxRequestSize)) {
            $this->response->error(413, 'EntityTooLarge', 'Request too large');
        }
```

Replace range parse call:

```php
[$isValidRange, $start, $end] = $this->validator->parseRange($range, $fileSize);
```

Remove private methods `parseRange()`, `isValidBucketName()`, `isValidObjectKey()`, and `isPositiveInteger()` from `S3Router`.

- [ ] **Step 4: Add files to lint script**

In `tests/lint.sh`, add:

```bash
  "$ROOT/src/S3/RequestValidator.php"
  "$ROOT/tests/unit/request-validator.php"
```

- [ ] **Step 5: Run validator test**

Run:

```bash
php tests/unit/request-validator.php
```

Expected: `[PASS] RequestValidator tests passed`.

- [ ] **Step 6: Run lint**

Run:

```bash
tests/lint.sh
```

Expected: PASS.

- [ ] **Step 7: Run integration tests**

Run only if safe to write to local `data/`:

```bash
tests/integration/run.sh
```

Expected: `[PASS] All integration scenarios passed`.

## Task 7: Add Storage Metadata and Stream Methods

**Files:**
- Modify: `src/Storage/FileStorage.php`
- Modify: `src/S3/S3Router.php`

- [ ] **Step 1: Add storage methods**

In `src/Storage/FileStorage.php`, add public methods after `objectExists()`:

```php
    public function objectMetadata(string $bucket, string $key): ?array
    {
        $filePath = $this->objectPath($bucket, $key);
        if (!is_file($filePath)) {
            return null;
        }

        $size = filesize($filePath);
        if ($size === false) {
            throw new RuntimeException('Failed to read file metadata');
        }

        return [
            'size' => $size,
            'mimeType' => mime_content_type($filePath) ?: 'application/octet-stream',
        ];
    }

    public function openObjectReadStream(string $bucket, string $key)
    {
        $stream = fopen($this->objectPath($bucket, $key), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Failed to open file');
        }

        return $stream;
    }
```

- [ ] **Step 2: Update GET handler to use metadata/stream**

In `src/S3/S3Router.php` `handleGet()`, replace raw object path, `is_file`, `filesize`, `fopen`, and `mime_content_type` setup with:

```php
        $metadata = $this->storage->objectMetadata($bucket, $key);
        if ($metadata === null) {
            $this->response->error(404, 'NoSuchKey', 'Object not found', $this->resource($bucket, $key));
        }

        $fileSize = (int) $metadata['size'];
        $fp = $this->storage->openObjectReadStream($bucket, $key);
        $mimeType = (string) $metadata['mimeType'];
```

- [ ] **Step 3: Update HEAD handler to use metadata**

In `handleHead()`, replace raw path, `is_file`, `filesize`, and `mime_content_type` with:

```php
        $metadata = $this->storage->objectMetadata($bucket, $key);
        if ($metadata === null) {
            $this->response->error(404, 'NoSuchKey', 'Resource not found', $this->resource($bucket, $key));
        }

        header('Content-Length: ' . (int) $metadata['size']);
        header('Content-Type: ' . (string) $metadata['mimeType']);
        http_response_code(200);
        exit;
```

- [ ] **Step 4: Run lint and tests**

Run:

```bash
tests/lint.sh
php tests/unit/request-validator.php
php tests/unit/config-loader.php
```

Expected: all PASS.

- [ ] **Step 5: Run integration tests if safe**

Run:

```bash
tests/integration/run.sh
```

Expected: `[PASS] All integration scenarios passed`.

## Task 8: Add Response Object Header Helpers

**Files:**
- Modify: `src/S3/S3Response.php`
- Modify: `src/S3/S3Router.php`

- [ ] **Step 1: Add response helpers**

In `src/S3/S3Response.php`, add public methods before `sendXml()`:

```php
    public function sendObjectHeaders(int $status, int $length, string $mimeType, string $filename, bool $attachment = true): void
    {
        http_response_code($status);
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $length);
        if ($attachment) {
            header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\\\"") . '"');
        }
        header('Cache-Control: private');
        header('Pragma: public');
    }

    public function sendRangeHeader(int $start, int $end, int $fileSize): void
    {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }

    public function sendInvalidRangeHeader(int $fileSize): void
    {
        header('Content-Range: bytes */' . $fileSize);
    }
```

- [ ] **Step 2: Use helpers in GET handler**

Replace direct range/content headers in `handleGet()` with:

```php
                $this->response->sendInvalidRangeHeader($fileSize);
                http_response_code(416);
                exit;
```

and:

```php
                $this->response->sendRangeHeader($start, $end, $fileSize);
```

Replace object header block with:

```php
        $this->response->sendObjectHeaders($status, $length, $mimeType, basename($key));
```

- [ ] **Step 3: Use helper in HEAD handler**

Replace HEAD direct headers with:

```php
        $this->response->sendObjectHeaders(200, (int) $metadata['size'], (string) $metadata['mimeType'], basename($key), false);
        exit;
```

- [ ] **Step 4: Run lint and tests**

Run:

```bash
tests/lint.sh
php tests/unit/request-validator.php
php tests/unit/config-loader.php
```

Expected: all PASS.

- [ ] **Step 5: Run integration tests if safe**

Run:

```bash
tests/integration/run.sh
```

Expected: `[PASS] All integration scenarios passed`.

## Task 9: Update Documentation

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Update TLDR**

Change TLDR to state:

```markdown
Set your web server root to this project's `public/` directory, configure credentials with environment variables or `config/config.php`, then route all requests to `public/index.php`.
```

- [ ] **Step 2: Update installation steps**

Replace root deploy wording with:

```markdown
1. Deploy this project outside the public web root when possible.
2. Set Apache/Nginx document root to `/path/to/mini-s3/public`.
3. Create a `data` directory, preferably outside the web root, and set `DATA_DIR` to that path.
4. Configure credentials using environment variables or a local `config/config.php` copied from `config.example.php`.
```

- [ ] **Step 3: Update Apache example**

Show `.htaccess` inside `public/` or vhost config using `public/` as document root:

```apache
DocumentRoot /path/to/mini-s3/public

<Directory /path/to/mini-s3/public>
    AllowOverride All
    Require all granted
</Directory>
```

Rewrite target remains `index.php` because it is now in `public/`.

- [ ] **Step 4: Update Nginx example**

Set:

```nginx
root /path/to/mini-s3/public;
index index.php;
```

Remove `location ~ ^/data/` from primary recommended config because data should not be under `public/`. Add separate fallback note for project-root data protection.

- [ ] **Step 5: Add env config docs**

Add section:

```markdown
### Environment Configuration

`ConfigLoader` reads `config/config.php` first, then environment variables override file values.

- `MINI_S3_DATA_DIR`
- `MINI_S3_MAX_REQUEST_SIZE`
- `MINI_S3_CREDENTIALS_JSON`
- `MINI_S3_PUBLIC_READ_ALL_BUCKETS`
- `MINI_S3_AUTH_DEBUG_LOG`
- `MINI_S3_ALLOW_HOST_CANDIDATE_FALLBACKS`

Example:

```bash
export MINI_S3_CREDENTIALS_JSON='{"prod-key":"prod-secret"}'
export MINI_S3_DATA_DIR='/var/lib/mini-s3/data'
```
```

- [ ] **Step 6: Add tooling docs**

Add section:

```markdown
### Development Checks

```bash
tests/lint.sh
tests/integration/run.sh
composer lint
composer test
composer check
```

`composer` is optional and only provides development scripts. Runtime does not require Composer.
```

- [ ] **Step 7: Run lint**

Run:

```bash
tests/lint.sh
```

Expected: PASS.

## Task 10: Final Verification

**Files:**
- All changed files

- [ ] **Step 1: Check status**

Run:

```bash
git status --short
```

Expected: shows intended changes only. `.env` and `data/` may remain untracked user files; do not add them.

- [ ] **Step 2: Run lint**

Run:

```bash
tests/lint.sh
```

Expected: PASS.

- [ ] **Step 3: Run unit scripts**

Run:

```bash
php tests/unit/config-loader.php
php tests/unit/request-validator.php
```

Expected:

```text
[PASS] ConfigLoader tests passed
[PASS] RequestValidator tests passed
```

- [ ] **Step 4: Run integration tests only if safe**

Run:

```bash
tests/integration/run.sh
```

Expected:

```text
[PASS] All integration scenarios passed
```

If not safe because local `data/` contains important objects, skip and report clearly: `Integration tests not run because they write to data/`.

- [ ] **Step 5: Review no secrets staged**

Run:

```bash
git status --short
```

Expected: `.env`, `data/`, and local `config/config.php` are not staged.

- [ ] **Step 6: Commit final changes**

Only commit if user explicitly requested commits.

```bash
git add .gitignore composer.json config.example.php public/index.php src/Config/ConfigLoader.php src/S3/RequestValidator.php src/S3/S3Response.php src/S3/S3Router.php src/Storage/FileStorage.php tests/lint.sh tests/unit/config-loader.php tests/unit/request-validator.php tests/integration/run.sh README.md docs/superpowers/specs/2026-05-04-mini-s3-architecture-hardening-design.md docs/superpowers/plans/2026-05-04-mini-s3-architecture-hardening.md
git add -u config/config.php
git commit -m "chore: harden mini-s3 architecture"
```

## Self-Review Notes

- Spec coverage: deployment boundary Task 9; config boundary Tasks 2-4; tooling Task 1; request validation Tasks 5-6; storage boundary Task 7; response boundary Task 8; tests Tasks 3, 5, 10.
- Red-flag scan: no incomplete sections or vague future work remain.
- Type consistency: `RequestValidator`, `objectMetadata()`, `openObjectReadStream()`, and response helper names are defined before use.
- Scope check: plan remains one coherent hardening project; no independent subsystem requires separate spec.
