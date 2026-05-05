# Admin Installer UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a dependency-free `/_` installer and admin UI for Mini S3 that can create/edit local config and show filesystem storage basics.

**Architecture:** Keep S3 object handling stable by routing only paths beginning with `/_` into a new `MiniS3\Admin` surface before constructing `S3Router`. Add small focused PHP classes for config writing, session auth/CSRF, stats scanning, HTML rendering, and admin routing.

**Tech Stack:** PHP 8.0+, plain PHP sessions, filesystem config, existing bash/PHP test harness, no new Composer dependencies.

---

## File Structure

- Create `src/Admin/AdminAuth.php`: starts sessions when needed, verifies `ADMIN_PASSWORD_HASH`, manages login/logout state, regenerates session ids, and creates/verifies CSRF tokens.
- Create `src/Admin/AdminConfigWriter.php`: validates config input, normalizes config arrays, writes `config/config.php` atomically, and refuses installer overwrite.
- Create `src/Admin/AdminStats.php`: scans `DATA_DIR` and returns bucket count, object count, total bytes, and directory status.
- Create `src/Admin/AdminRenderer.php`: renders dependency-free HTML pages for installer, login, dashboard, and config using compact top nav.
- Create `src/Admin/AdminRouter.php`: handles `/_`, `/_/config`, and `/_/logout`, delegates validation/auth/rendering, and returns HTML responses.
- Modify `src/Config/ConfigLoader.php`: add default `ADMIN_PASSWORD_HASH`, normalize it, preserve existing env override behavior.
- Modify `config.example.php`: document `ADMIN_PASSWORD_HASH` with an empty default value.
- Modify `public/index.php`: require admin files and dispatch `/_` requests before loading runtime config for S3.
- Modify `tests/lint.sh`: lint new admin files and new unit tests.
- Modify `tests/unit/config-loader.php`: assert `ADMIN_PASSWORD_HASH` is loaded and normalized.
- Create `tests/unit/admin-config-writer.php`: unit tests for validation and config writing.
- Create `tests/unit/admin-auth.php`: unit tests for password verification and CSRF helpers.
- Create `tests/unit/admin-stats.php`: unit tests for filesystem statistics.
- Modify `tests/integration/run.sh`: add CLI harness checks that `/_` is separated from S3 routing and renders installer/admin HTML.

---

### Task 1: ConfigLoader Supports Admin Password Hash

**Files:**
- Modify: `src/Config/ConfigLoader.php:13-24`
- Modify: `config.example.php:3-16`
- Modify: `tests/unit/config-loader.php:69-76`
- Modify: `tests/lint.sh:7-22`

- [ ] **Step 1: Add failing ConfigLoader assertion**

Add `ADMIN_PASSWORD_HASH` to the config fixture and assert it is preserved:

```php
$base = tempProject([
    'CREDENTIALS' => ['file-key' => 'file-secret'],
    'PUBLIC_READ_ALL_BUCKETS' => true,
    'ADMIN_PASSWORD_HASH' => '$2y$10$abcdefghijklmnopqrstuuJ8CmYLcOeO9mRXuQzknW4f4mSb1zZ9K',
]);
$config = ConfigLoader::load($base);
assertSameValue(['file-key' => 'file-secret'], $config['CREDENTIALS'], 'config file credentials load');
assertSameValue(true, $config['PUBLIC_READ_ALL_BUCKETS'], 'config file public read loads');
assertSameValue('$2y$10$abcdefghijklmnopqrstuuJ8CmYLcOeO9mRXuQzknW4f4mSb1zZ9K', $config['ADMIN_PASSWORD_HASH'], 'admin password hash loads');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/unit/config-loader.php`

Expected: FAIL with `admin password hash loads` because the key is not defined or normalized yet.

- [ ] **Step 3: Add ConfigLoader default and normalization**

In `src/Config/ConfigLoader.php`, add the default key:

```php
$config = [
    'DATA_DIR' => $baseDir . '/data',
    'MAX_REQUEST_SIZE' => 100 * 1024 * 1024,
    'CREDENTIALS' => [],
    'ALLOW_LEGACY_ACCESS_KEY_ONLY' => false,
    'ALLOWED_ACCESS_KEYS' => [],
    'CLOCK_SKEW_SECONDS' => 900,
    'MAX_PRESIGN_EXPIRES' => 604800,
    'AUTH_DEBUG_LOG' => '',
    'ALLOW_HOST_CANDIDATE_FALLBACKS' => false,
    'PUBLIC_READ_ALL_BUCKETS' => false,
    'ADMIN_PASSWORD_HASH' => '',
];
```

After existing boolean normalization, add:

```php
$config['ADMIN_PASSWORD_HASH'] = trim((string) ($config['ADMIN_PASSWORD_HASH'] ?? ''));
```

- [ ] **Step 4: Document example config key**

Add to `config.example.php`:

```php
'ADMIN_PASSWORD_HASH' => '',
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php tests/unit/config-loader.php`

Expected: PASS with `[PASS] ConfigLoader tests passed`.

- [ ] **Step 6: Run lint for changed PHP files**

Run: `tests/lint.sh`

Expected: all listed files report `No syntax errors detected`.

- [ ] **Step 7: Commit**

```bash
git add src/Config/ConfigLoader.php config.example.php tests/unit/config-loader.php
git commit -m "feat: add admin password config key"
```

---

### Task 2: Config Writer Validation And Atomic Writes

**Files:**
- Create: `src/Admin/AdminConfigWriter.php`
- Create: `tests/unit/admin-config-writer.php`
- Modify: `tests/lint.sh:7-22`

- [ ] **Step 1: Write failing config writer tests**

Create `tests/unit/admin-config-writer.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminConfigWriter.php';

use MiniS3\Admin\AdminConfigWriter;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

function assertTrueValue(bool $actual, string $message): void
{
    assertSameValue(true, $actual, $message);
}

function assertThrowsMessage(callable $callback, string $needle, string $message): void
{
    global $failures;
    try {
        $callback();
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), $needle)) {
            return;
        }
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: wrong exception " . $e->getMessage() . PHP_EOL);
        return;
    }

    $failures++;
    fwrite(STDERR, "[FAIL] {$message}: expected exception" . PHP_EOL);
}

$base = sys_get_temp_dir() . '/mini-s3-writer-' . bin2hex(random_bytes(4));
$dataDir = $base . '/data';

$writer = new AdminConfigWriter($base);
$config = $writer->buildConfig([
    'admin_password' => 'secret-pass',
    'admin_password_confirm' => 'secret-pass',
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
    'max_request_size' => '1048576',
    'public_read_all_buckets' => '1',
    'auth_debug_log' => '',
    'allow_host_candidate_fallbacks' => '',
    'clock_skew_seconds' => '900',
    'max_presign_expires' => '604800',
]);

assertSameValue($dataDir, $config['DATA_DIR'], 'data dir is normalized');
assertSameValue(1048576, $config['MAX_REQUEST_SIZE'], 'max request size is integer');
assertSameValue(['access-one' => 'secret-one'], $config['CREDENTIALS'], 'credentials are built');
assertSameValue(true, password_verify('secret-pass', $config['ADMIN_PASSWORD_HASH']), 'admin password is hashed');
assertSameValue(true, $config['PUBLIC_READ_ALL_BUCKETS'], 'public read checkbox is parsed');

$writer->writeInstallerConfig($config);
assertTrueValue(is_file($base . '/config/config.php'), 'config file is written');
$loaded = require $base . '/config/config.php';
assertSameValue(['access-one' => 'secret-one'], $loaded['CREDENTIALS'], 'written config loads');

assertThrowsMessage(fn() => $writer->writeInstallerConfig($config), 'already exists', 'installer refuses overwrite');
assertThrowsMessage(fn() => $writer->buildConfig([
    'admin_password' => 'one',
    'admin_password_confirm' => 'two',
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
]), 'match', 'password mismatch fails');
assertThrowsMessage(fn() => $writer->buildConfig([
    'admin_password' => 'secret-pass',
    'admin_password_confirm' => 'secret-pass',
    'data_dir' => $dataDir,
    'access_key' => '',
    'secret_key' => 'secret-one',
]), 'Access key', 'empty access key fails');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminConfigWriter tests passed" . PHP_EOL;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/unit/admin-config-writer.php`

Expected: FAIL because `src/Admin/AdminConfigWriter.php` does not exist.

- [ ] **Step 3: Implement AdminConfigWriter**

Create `src/Admin/AdminConfigWriter.php`:

```php
<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use RuntimeException;

final class AdminConfigWriter
{
    public function __construct(private readonly string $baseDir)
    {
    }

    public function buildConfig(array $input, array $existing = []): array
    {
        $dataDir = trim((string) ($input['data_dir'] ?? ''));
        if ($dataDir === '') {
            throw new RuntimeException('Data directory is required');
        }

        $accessKey = trim((string) ($input['access_key'] ?? ''));
        if ($accessKey === '') {
            throw new RuntimeException('Access key is required');
        }

        $secretKey = (string) ($input['secret_key'] ?? '');
        if ($secretKey === '') {
            throw new RuntimeException('Secret key is required');
        }

        $adminPasswordHash = trim((string) ($existing['ADMIN_PASSWORD_HASH'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');
        $passwordConfirm = (string) ($input['admin_password_confirm'] ?? '');
        if ($password !== '' || $passwordConfirm !== '' || $adminPasswordHash === '') {
            if ($password === '' || $password !== $passwordConfirm) {
                throw new RuntimeException('Admin passwords must match');
            }
            $adminPasswordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        $maxRequestSize = $this->positiveInt($input['max_request_size'] ?? 100 * 1024 * 1024, 'Max request size');
        $clockSkewSeconds = $this->positiveInt($input['clock_skew_seconds'] ?? 900, 'Clock skew seconds');
        $maxPresignExpires = $this->positiveInt($input['max_presign_expires'] ?? 604800, 'Max presign expires');

        return [
            'DATA_DIR' => $dataDir,
            'MAX_REQUEST_SIZE' => $maxRequestSize,
            'CREDENTIALS' => [$accessKey => $secretKey],
            'ALLOW_LEGACY_ACCESS_KEY_ONLY' => false,
            'ALLOWED_ACCESS_KEYS' => [],
            'CLOCK_SKEW_SECONDS' => $clockSkewSeconds,
            'MAX_PRESIGN_EXPIRES' => $maxPresignExpires,
            'AUTH_DEBUG_LOG' => trim((string) ($input['auth_debug_log'] ?? '')),
            'ALLOW_HOST_CANDIDATE_FALLBACKS' => $this->checkbox($input, 'allow_host_candidate_fallbacks'),
            'PUBLIC_READ_ALL_BUCKETS' => $this->checkbox($input, 'public_read_all_buckets'),
            'ADMIN_PASSWORD_HASH' => $adminPasswordHash,
        ];
    }

    public function writeInstallerConfig(array $config): void
    {
        $path = $this->configPath();
        if (is_file($path)) {
            throw new RuntimeException('Config file already exists; log in instead');
        }

        $this->ensureWritableDataDir((string) $config['DATA_DIR']);
        $this->writeConfig($config);
    }

    public function writeConfig(array $config): void
    {
        $configDir = dirname($this->configPath());
        if (!is_dir($configDir) && !mkdir($configDir, 0777, true) && !is_dir($configDir)) {
            throw new RuntimeException('Config directory cannot be created');
        }
        if (!is_writable($configDir)) {
            throw new RuntimeException('Config directory is not writable');
        }

        $tmpPath = $this->configPath() . '.tmp.' . bin2hex(random_bytes(4));
        $php = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($tmpPath, $php, LOCK_EX) === false) {
            throw new RuntimeException('Config file cannot be written');
        }
        if (!rename($tmpPath, $this->configPath())) {
            @unlink($tmpPath);
            throw new RuntimeException('Config file cannot be saved');
        }
    }

    public function ensureWritableDataDir(string $dataDir): void
    {
        if (!is_dir($dataDir) && !mkdir($dataDir, 0777, true) && !is_dir($dataDir)) {
            throw new RuntimeException('Data directory cannot be created');
        }
        if (!is_readable($dataDir) || !is_writable($dataDir)) {
            throw new RuntimeException('Data directory must be readable and writable');
        }
    }

    private function configPath(): string
    {
        return $this->baseDir . '/config/config.php';
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $int = (int) $value;
        if ($int < 1) {
            throw new RuntimeException($label . ' must be a positive integer');
        }

        return $int;
    }

    private function checkbox(array $input, string $key): bool
    {
        return isset($input[$key]) && in_array((string) $input[$key], ['1', 'true', 'on', 'yes'], true);
    }
}
```

- [ ] **Step 4: Add new files to lint script**

Add these entries to `tests/lint.sh` files array:

```bash
  "$ROOT/src/Admin/AdminConfigWriter.php"
  "$ROOT/tests/unit/admin-config-writer.php"
```

- [ ] **Step 5: Run writer tests**

Run: `php tests/unit/admin-config-writer.php`

Expected: PASS with `[PASS] AdminConfigWriter tests passed`.

- [ ] **Step 6: Run lint**

Run: `tests/lint.sh`

Expected: all files pass PHP syntax checks.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/AdminConfigWriter.php tests/unit/admin-config-writer.php tests/lint.sh
git commit -m "feat: add admin config writer"
```

---

### Task 3: Admin Auth And CSRF Helpers

**Files:**
- Create: `src/Admin/AdminAuth.php`
- Create: `tests/unit/admin-auth.php`
- Modify: `tests/lint.sh:7-22`

- [ ] **Step 1: Write failing auth tests**

Create `tests/unit/admin-auth.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminAuth.php';

use MiniS3\Admin\AdminAuth;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

$_SESSION = [];
$hash = password_hash('admin-pass', PASSWORD_DEFAULT);
$auth = new AdminAuth($hash);

assertSameValue(false, $auth->isAuthenticated(), 'new session is not authenticated');
assertSameValue(false, $auth->login('wrong-pass'), 'wrong password is rejected');
assertSameValue(false, $auth->isAuthenticated(), 'wrong login does not authenticate');
assertSameValue(true, $auth->login('admin-pass'), 'right password is accepted');
assertSameValue(true, $auth->isAuthenticated(), 'right login authenticates');

$token = $auth->csrfToken();
assertSameValue(true, is_string($token) && strlen($token) >= 32, 'csrf token is generated');
assertSameValue(true, $auth->verifyCsrfToken($token), 'csrf token verifies');
assertSameValue(false, $auth->verifyCsrfToken('bad-token'), 'bad csrf token fails');

$auth->logout();
assertSameValue(false, $auth->isAuthenticated(), 'logout clears authentication');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminAuth tests passed" . PHP_EOL;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/unit/admin-auth.php`

Expected: FAIL because `src/Admin/AdminAuth.php` does not exist.

- [ ] **Step 3: Implement AdminAuth**

Create `src/Admin/AdminAuth.php`:

```php
<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminAuth
{
    private const AUTH_KEY = 'mini_s3_admin_authenticated';
    private const CSRF_KEY = 'mini_s3_admin_csrf_token';

    public function __construct(private readonly string $passwordHash)
    {
        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            session_start();
        }
    }

    public function isConfigured(): bool
    {
        return $this->passwordHash !== '';
    }

    public function isAuthenticated(): bool
    {
        return (bool) ($_SESSION[self::AUTH_KEY] ?? false);
    }

    public function login(string $password): bool
    {
        if (!$this->isConfigured() || !password_verify($password, $this->passwordHash)) {
            return false;
        }

        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
        $_SESSION[self::AUTH_KEY] = true;

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::AUTH_KEY], $_SESSION[self::CSRF_KEY]);
        if (PHP_SAPI !== 'cli') {
            session_destroy();
        }
    }

    public function csrfToken(): string
    {
        $token = (string) ($_SESSION[self::CSRF_KEY] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $token;
        }

        return $token;
    }

    public function verifyCsrfToken(string $token): bool
    {
        $expected = (string) ($_SESSION[self::CSRF_KEY] ?? '');

        return $expected !== '' && hash_equals($expected, $token);
    }
}
```

- [ ] **Step 4: Add new files to lint script**

Add these entries to `tests/lint.sh` files array:

```bash
  "$ROOT/src/Admin/AdminAuth.php"
  "$ROOT/tests/unit/admin-auth.php"
```

- [ ] **Step 5: Run auth tests**

Run: `php tests/unit/admin-auth.php`

Expected: PASS with `[PASS] AdminAuth tests passed`.

- [ ] **Step 6: Run lint**

Run: `tests/lint.sh`

Expected: all files pass PHP syntax checks.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/AdminAuth.php tests/unit/admin-auth.php tests/lint.sh
git commit -m "feat: add admin auth helpers"
```

---

### Task 4: Filesystem Dashboard Stats

**Files:**
- Create: `src/Admin/AdminStats.php`
- Create: `tests/unit/admin-stats.php`
- Modify: `tests/lint.sh:7-22`

- [ ] **Step 1: Write failing stats tests**

Create `tests/unit/admin-stats.php`:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminStats.php';

use MiniS3\Admin\AdminStats;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

$base = sys_get_temp_dir() . '/mini-s3-stats-' . bin2hex(random_bytes(4));
mkdir($base . '/bucket-one/nested', 0777, true);
mkdir($base . '/bucket-two', 0777, true);
mkdir($base . '/.multipart/internal', 0777, true);
file_put_contents($base . '/bucket-one/a.txt', '12345');
file_put_contents($base . '/bucket-one/nested/b.txt', '123');
file_put_contents($base . '/bucket-two/c.txt', '12');
file_put_contents($base . '/.multipart/internal/part', 'ignore');

$stats = (new AdminStats())->scan($base);
assertSameValue($base, $stats['data_dir'], 'data dir is returned');
assertSameValue('ok', $stats['status'], 'status is ok');
assertSameValue(2, $stats['bucket_count'], 'bucket count ignores internal directories');
assertSameValue(3, $stats['object_count'], 'object count includes nested files');
assertSameValue(10, $stats['total_bytes'], 'total size sums object bytes');

$missing = (new AdminStats())->scan($base . '/missing');
assertSameValue('missing', $missing['status'], 'missing directory status is reported');
assertSameValue(0, $missing['bucket_count'], 'missing directory has no buckets');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminStats tests passed" . PHP_EOL;
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/unit/admin-stats.php`

Expected: FAIL because `src/Admin/AdminStats.php` does not exist.

- [ ] **Step 3: Implement AdminStats**

Create `src/Admin/AdminStats.php`:

```php
<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class AdminStats
{
    public function scan(string $dataDir): array
    {
        $stats = [
            'data_dir' => $dataDir,
            'status' => 'ok',
            'bucket_count' => 0,
            'object_count' => 0,
            'total_bytes' => 0,
        ];

        if (!is_dir($dataDir)) {
            $stats['status'] = 'missing';
            return $stats;
        }
        if (!is_readable($dataDir)) {
            $stats['status'] = 'unreadable';
            return $stats;
        }
        if (!is_writable($dataDir)) {
            $stats['status'] = 'not_writable';
        }

        $bucketDirs = glob($dataDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($bucketDirs as $bucketDir) {
            if (basename($bucketDir) === '.multipart') {
                continue;
            }
            $stats['bucket_count']++;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($bucketDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $stats['object_count']++;
                $stats['total_bytes'] += $file->getSize();
            }
        }

        return $stats;
    }
}
```

- [ ] **Step 4: Add new files to lint script**

Add these entries to `tests/lint.sh` files array:

```bash
  "$ROOT/src/Admin/AdminStats.php"
  "$ROOT/tests/unit/admin-stats.php"
```

- [ ] **Step 5: Run stats tests**

Run: `php tests/unit/admin-stats.php`

Expected: PASS with `[PASS] AdminStats tests passed`.

- [ ] **Step 6: Run lint**

Run: `tests/lint.sh`

Expected: all files pass PHP syntax checks.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/AdminStats.php tests/unit/admin-stats.php tests/lint.sh
git commit -m "feat: add admin storage stats"
```

---

### Task 5: HTML Renderer For Installer And Admin Pages

**Files:**
- Create: `src/Admin/AdminRenderer.php`
- Modify: `tests/lint.sh:7-22`

- [ ] **Step 1: Create renderer smoke test using PHP lint first**

Add this file to `tests/lint.sh` before implementation so lint fails until it exists:

```bash
  "$ROOT/src/Admin/AdminRenderer.php"
```

- [ ] **Step 2: Run lint to verify it fails**

Run: `tests/lint.sh`

Expected: FAIL because `src/Admin/AdminRenderer.php` does not exist.

- [ ] **Step 3: Implement AdminRenderer**

Create `src/Admin/AdminRenderer.php`:

```php
<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminRenderer
{
    public function installer(array $values, array $errors, string $csrfToken): string
    {
        return $this->layout('Install Mini S3', $this->form('/_', $values, $errors, $csrfToken, true), false);
    }

    public function login(string $error, string $csrfToken): string
    {
        $errorHtml = $error === '' ? '' : '<div class="error">' . $this->e($error) . '</div>';
        $body = $errorHtml . '<form method="post" action="/_">'
            . '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">'
            . '<label>Password<input type="password" name="password" required></label>'
            . '<button type="submit">Log in</button>'
            . '</form>';

        return $this->layout('Mini S3 Admin Login', $body, false);
    }

    public function dashboard(array $stats): string
    {
        $body = '<div class="cards">'
            . $this->statCard('Buckets', (string) $stats['bucket_count'])
            . $this->statCard('Objects', (string) $stats['object_count'])
            . $this->statCard('Storage', $this->formatBytes((int) $stats['total_bytes']))
            . $this->statCard('Data Dir', $this->e((string) $stats['status']))
            . '</div>'
            . '<section class="panel"><h2>Data directory</h2><code>' . $this->e((string) $stats['data_dir']) . '</code></section>';

        return $this->layout('Dashboard', $body, true);
    }

    public function config(array $values, array $errors, string $csrfToken): string
    {
        return $this->layout('Config', $this->form('/_/config', $values, $errors, $csrfToken, false), true);
    }

    private function form(string $action, array $values, array $errors, string $csrfToken, bool $installer): string
    {
        $errorHtml = '';
        foreach ($errors as $error) {
            $errorHtml .= '<div class="error">' . $this->e((string) $error) . '</div>';
        }

        $passwordLabel = $installer ? 'Admin password' : 'New admin password';
        $passwordRequired = $installer ? ' required' : '';

        return $errorHtml . '<form method="post" action="' . $this->e($action) . '">'
            . '<input type="hidden" name="csrf_token" value="' . $this->e($csrfToken) . '">'
            . '<label>' . $passwordLabel . '<input type="password" name="admin_password"' . $passwordRequired . '></label>'
            . '<label>Confirm admin password<input type="password" name="admin_password_confirm"' . $passwordRequired . '></label>'
            . '<label>Data directory<input name="data_dir" value="' . $this->e((string) ($values['data_dir'] ?? '')) . '" required></label>'
            . '<label>Access key<input name="access_key" value="' . $this->e((string) ($values['access_key'] ?? '')) . '" required></label>'
            . '<label>Secret key<input type="password" name="secret_key" value="' . $this->e((string) ($values['secret_key'] ?? '')) . '" required></label>'
            . '<details><summary>Advanced</summary>'
            . '<label>Max request size<input type="number" min="1" name="max_request_size" value="' . $this->e((string) ($values['max_request_size'] ?? '104857600')) . '"></label>'
            . '<label><input type="checkbox" name="public_read_all_buckets" value="1"' . $this->checked($values, 'public_read_all_buckets') . '> Public read all buckets</label>'
            . '<label>Auth debug log<input name="auth_debug_log" value="' . $this->e((string) ($values['auth_debug_log'] ?? '')) . '"></label>'
            . '<label><input type="checkbox" name="allow_host_candidate_fallbacks" value="1"' . $this->checked($values, 'allow_host_candidate_fallbacks') . '> Allow host candidate fallbacks</label>'
            . '<label>Clock skew seconds<input type="number" min="1" name="clock_skew_seconds" value="' . $this->e((string) ($values['clock_skew_seconds'] ?? '900')) . '"></label>'
            . '<label>Max presign expires<input type="number" min="1" name="max_presign_expires" value="' . $this->e((string) ($values['max_presign_expires'] ?? '604800')) . '"></label>'
            . '</details>'
            . '<button type="submit">Save</button>'
            . '</form>';
    }

    private function layout(string $title, string $body, bool $nav): string
    {
        $navigation = $nav ? '<nav><a href="/_">Dashboard</a><a href="/_/config">Config</a><a href="/_/logout">Logout</a></nav>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $this->e($title) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,sans-serif;margin:0;background:#f7f7f8;color:#17202a}header{background:#101827;color:white;padding:16px 20px;display:flex;gap:20px;align-items:center;justify-content:space-between;flex-wrap:wrap}header a{color:white;text-decoration:none;margin-right:14px}main{max-width:920px;margin:24px auto;padding:0 16px}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:14px}.card,.panel,form{background:white;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}label{display:block;margin:14px 0;font-weight:600}input{box-sizing:border-box;width:100%;padding:10px;margin-top:6px;border:1px solid #cbd5e1;border-radius:8px}input[type=checkbox]{width:auto}button{background:#1d4ed8;color:white;border:0;border-radius:8px;padding:10px 14px;font-weight:700}.error{background:#fee2e2;border:1px solid #fca5a5;color:#991b1b;padding:10px;border-radius:8px;margin-bottom:10px}code{word-break:break-all}</style>'
            . '</head><body><header><strong>Mini S3</strong>' . $navigation . '</header><main><h1>' . $this->e($title) . '</h1>' . $body . '</main></body></html>';
    }

    private function statCard(string $label, string $value): string
    {
        return '<section class="card"><h2>' . $this->e($value) . '</h2><p>' . $this->e($label) . '</p></section>';
    }

    private function checked(array $values, string $key): string
    {
        return !empty($values[$key]) ? ' checked' : '';
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }

        return $bytes . ' B';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
```

- [ ] **Step 4: Run lint**

Run: `tests/lint.sh`

Expected: all files pass PHP syntax checks.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/AdminRenderer.php tests/lint.sh
git commit -m "feat: add admin html renderer"
```

---

### Task 6: Admin Router For Installer, Login, Dashboard, Config, Logout

**Files:**
- Create: `src/Admin/AdminRouter.php`
- Modify: `tests/lint.sh:7-22`

- [ ] **Step 1: Add router to lint before implementation**

Add this file to `tests/lint.sh`:

```bash
  "$ROOT/src/Admin/AdminRouter.php"
```

- [ ] **Step 2: Run lint to verify it fails**

Run: `tests/lint.sh`

Expected: FAIL because `src/Admin/AdminRouter.php` does not exist.

- [ ] **Step 3: Implement AdminRouter**

Create `src/Admin/AdminRouter.php`:

```php
<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use MiniS3\Config\ConfigLoader;
use RuntimeException;
use Throwable;

final class AdminRouter
{
    public function __construct(
        private readonly string $baseDir,
        private readonly string $method,
        private readonly string $uri,
        private readonly array $post
    ) {
    }

    public function handle(): never
    {
        try {
            $renderer = new AdminRenderer();
            $writer = new AdminConfigWriter($this->baseDir);
            $configPath = $this->baseDir . '/config/config.php';

            if (!is_file($configPath)) {
                $auth = new AdminAuth('');
                $this->handleInstaller($renderer, $writer, $auth);
            }

            $config = ConfigLoader::load($this->baseDir);
            $auth = new AdminAuth((string) ($config['ADMIN_PASSWORD_HASH'] ?? ''));
            $path = parse_url($this->uri, PHP_URL_PATH) ?: '/_';

            if ($path === '/_/logout') {
                $auth->logout();
                $this->redirect('/_');
            }

            if (!$auth->isAuthenticated()) {
                $this->handleLogin($renderer, $auth);
            }

            if ($path === '/_/config') {
                $this->handleConfig($renderer, $writer, $auth, $config);
            }

            $stats = (new AdminStats())->scan((string) $config['DATA_DIR']);
            $this->html($renderer->dashboard($stats));
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/html; charset=UTF-8');
            echo '<!doctype html><title>Admin Error</title><h1>Admin Error</h1><p>' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
            exit;
        }
    }

    private function handleInstaller(AdminRenderer $renderer, AdminConfigWriter $writer, AdminAuth $auth): never
    {
        $values = $this->defaultValues();
        if ($this->method === 'POST') {
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->installer($this->post + $values, ['CSRF token is invalid'], $auth->csrfToken()), 400);
            }
            try {
                $config = $writer->buildConfig($this->post);
                $writer->writeInstallerConfig($config);
                $loginAuth = new AdminAuth((string) $config['ADMIN_PASSWORD_HASH']);
                $loginAuth->login((string) $this->post['admin_password']);
                $this->redirect('/_');
            } catch (RuntimeException $e) {
                $this->html($renderer->installer($this->post + $values, [$e->getMessage()], $auth->csrfToken()), 400);
            }
        }

        $this->html($renderer->installer($values, [], $auth->csrfToken()));
    }

    private function handleLogin(AdminRenderer $renderer, AdminAuth $auth): never
    {
        if ($this->method === 'POST') {
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->login('CSRF token is invalid', $auth->csrfToken()), 400);
            }
            if ($auth->login((string) ($this->post['password'] ?? ''))) {
                $this->redirect('/_');
            }
            $this->html($renderer->login('Invalid login password', $auth->csrfToken()), 401);
        }

        $this->html($renderer->login('', $auth->csrfToken()));
    }

    private function handleConfig(AdminRenderer $renderer, AdminConfigWriter $writer, AdminAuth $auth, array $config): never
    {
        $values = $this->valuesFromConfig($config);
        if ($this->method === 'POST') {
            if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
                $this->html($renderer->config($this->post + $values, ['CSRF token is invalid'], $auth->csrfToken()), 400);
            }
            try {
                $newConfig = $writer->buildConfig($this->post, $config);
                $writer->ensureWritableDataDir((string) $newConfig['DATA_DIR']);
                $writer->writeConfig($newConfig);
                $this->redirect('/_/config');
            } catch (RuntimeException $e) {
                $this->html($renderer->config($this->post + $values, [$e->getMessage()], $auth->csrfToken()), 400);
            }
        }

        $this->html($renderer->config($values, [], $auth->csrfToken()));
    }

    private function defaultValues(): array
    {
        return [
            'data_dir' => $this->baseDir . '/data',
            'max_request_size' => '104857600',
            'clock_skew_seconds' => '900',
            'max_presign_expires' => '604800',
        ];
    }

    private function valuesFromConfig(array $config): array
    {
        $credentials = (array) ($config['CREDENTIALS'] ?? []);
        $accessKey = (string) array_key_first($credentials);

        return [
            'data_dir' => (string) $config['DATA_DIR'],
            'access_key' => $accessKey,
            'secret_key' => $accessKey === '' ? '' : (string) $credentials[$accessKey],
            'max_request_size' => (string) $config['MAX_REQUEST_SIZE'],
            'public_read_all_buckets' => (bool) $config['PUBLIC_READ_ALL_BUCKETS'],
            'auth_debug_log' => (string) $config['AUTH_DEBUG_LOG'],
            'allow_host_candidate_fallbacks' => (bool) $config['ALLOW_HOST_CANDIDATE_FALLBACKS'],
            'clock_skew_seconds' => (string) $config['CLOCK_SKEW_SECONDS'],
            'max_presign_expires' => (string) $config['MAX_PRESIGN_EXPIRES'],
        ];
    }

    private function html(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        echo $html;
        exit;
    }

    private function redirect(string $path): never
    {
        http_response_code(302);
        header('Location: ' . $path);
        exit;
    }
}
```

- [ ] **Step 4: Run lint**

Run: `tests/lint.sh`

Expected: all files pass PHP syntax checks.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/AdminRouter.php tests/lint.sh
git commit -m "feat: add admin router"
```

---

### Task 7: Wire `/_` Dispatch Into Front Controller

**Files:**
- Modify: `public/index.php:7-25`
- Modify: `tests/integration/run.sh:140-149`

- [ ] **Step 1: Add failing integration assertions for `/_` route**

In `tests/integration/run.sh`, after `echo "[INFO] Starting mini-s3 integration tests (CLI harness)"`, add:

```bash
# Admin installer route should render HTML and stay separate from S3 XML routing.
run_request GET "/_" "" "$TMP_DIR/admin-installer.body" "$TMP_DIR/admin-installer.meta" "Host: $SIGN_HOST"
assert_eq "200" "$(meta_status "$TMP_DIR/admin-installer.meta")" "Admin installer route should succeed"
assert_contains "Install Mini S3" "$TMP_DIR/admin-installer.body" "Admin installer should render setup page"
assert_not_contains "<?xml" "$TMP_DIR/admin-installer.body" "Admin installer should not render S3 XML"
```

- [ ] **Step 2: Run integration test to verify it fails**

Run: `tests/integration/run.sh`

Expected: FAIL because `/_` is still handled by S3 routing or config load errors.

- [ ] **Step 3: Require admin classes and dispatch before S3 config load**

In `public/index.php`, add requires after `ConfigLoader.php`:

```php
require_once BASE_PATH . '/src/Admin/AdminAuth.php';
require_once BASE_PATH . '/src/Admin/AdminConfigWriter.php';
require_once BASE_PATH . '/src/Admin/AdminStats.php';
require_once BASE_PATH . '/src/Admin/AdminRenderer.php';
require_once BASE_PATH . '/src/Admin/AdminRouter.php';
```

Add use statement:

```php
use MiniS3\Admin\AdminRouter;
```

Before `try { $config = ConfigLoader::load(BASE_PATH);`, add:

```php
$requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';
if ($requestPath === '/_' || str_starts_with($requestPath, '/_/')) {
    $adminRouter = new AdminRouter(
        BASE_PATH,
        (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        $requestUri,
        $_POST
    );
    $adminRouter->handle();
}
```

- [ ] **Step 4: Run integration test**

Run: `tests/integration/run.sh`

Expected: PASS through existing S3 tests and new admin route assertions.

- [ ] **Step 5: Run full check**

Run: `composer check`

Expected: lint, integration tests, and release archive tests pass.

- [ ] **Step 6: Commit**

```bash
git add public/index.php tests/integration/run.sh
git commit -m "feat: route admin ui under internal prefix"
```

---

### Task 8: Documentation And Release Archive Coverage

**Files:**
- Modify: `README.md`
- Modify: `tests/release-archive.sh`

- [ ] **Step 1: Add README section for admin installer**

In `README.md`, after the configuration section introduction, add:

```markdown
### Web Installer and Admin UI

Mini S3 reserves the `/_` route prefix for its built-in installer and admin UI.

If `config/config.php` does not exist, open `/_` in a browser to run the installer. The installer creates local config, sets an admin password, configures the data directory, and creates the first S3 access key and secret key.

After installation, open `/_` to log in. The admin dashboard shows bucket count, object count, total storage size, and data directory status. Use `/_/config` to edit local config values.

The admin UI edits `config/config.php`. Environment variables still override runtime config and are not edited by the UI.
```

- [ ] **Step 2: Ensure release archive includes admin sources**

In `tests/release-archive.sh`, add this assertion after the existing `src/S3/S3Router.php` assertion:

```bash
assert_zip_contains "src/Admin/AdminRouter.php"
```

- [ ] **Step 3: Run release archive test**

Run: `tests/release-archive.sh`

Expected: PASS and confirms admin files are present in release zip.

- [ ] **Step 4: Run full check**

Run: `composer check`

Expected: lint, integration tests, and release archive tests pass.

- [ ] **Step 5: Commit**

```bash
git add README.md tests/release-archive.sh
git commit -m "docs: document admin installer ui"
```

---

## Final Verification

- [ ] Run `composer check`.
- [ ] Manually open `/_` with no `config/config.php` and confirm installer renders.
- [ ] Complete installer in a temporary local setup and confirm redirect to dashboard.
- [ ] Log out and log back in with the configured admin password.
- [ ] Open `/_/config`, save a config change, and confirm `config/config.php` changes.
- [ ] Confirm a normal S3 request path such as `/bucket/key` still returns S3 XML or object responses, not admin HTML.
- [ ] Run `git status --short` and confirm only intentional files are changed.

---

### Task 9: Dashboard Connection Config Snippets

**Files:**
- Modify: `src/Admin/AdminRenderer.php:26-112`
- Modify: `src/Admin/AdminRouter.php:49-51`
- Modify: `tests/integration/run.sh`

- [ ] **Step 1: Add integration assertions for connection snippets**

Add an integration scenario that creates a temporary local config, requests `/_`, and asserts the dashboard contains generic S3 env vars, Laravel env vars, masked sensitive values, and the client-side show-sensitive toggle.

- [ ] **Step 2: Run integration test to verify it fails**

Run: `tests/integration/run.sh`

Expected: FAIL because dashboard does not render connection snippets yet.

- [ ] **Step 3: Pass config and endpoint into dashboard rendering**

In `AdminRouter`, derive endpoint from the current request scheme and host, then call `AdminRenderer::dashboard($stats, $config, $endpoint)`.

- [ ] **Step 4: Render generic S3 and Laravel `.env` snippets**

In `AdminRenderer`, update `dashboard()` to render a “Connection config” panel with two `<pre>` blocks. Generate masked and unmasked versions server-side, show masked by default, and use a small inline script for “Show sensitive” and copy buttons.

- [ ] **Step 5: Run integration test**

Run: `tests/integration/run.sh`

Expected: PASS and assertions confirm snippets render.

- [ ] **Step 6: Run full check**

Run: `composer check`

Expected: lint, integration tests, and release archive tests pass.

---

### Task 10: Public Read Primary Default

**Files:**
- Modify: `src/Config/ConfigLoader.php`
- Modify: `config.example.php`
- Modify: `src/Admin/AdminConfigWriter.php`
- Modify: `src/Admin/AdminRenderer.php`
- Modify: `src/Admin/AdminRouter.php`
- Modify: `tests/unit/config-loader.php`
- Modify: `tests/unit/admin-config-writer.php`

- [ ] **Step 1: Update tests for public read default**

Assert default config loads `PUBLIC_READ_ALL_BUCKETS=true` and installer config generation defaults the field to true when the checkbox key is absent.

- [ ] **Step 2: Run tests to verify failure**

Run: `php tests/unit/config-loader.php && php tests/unit/admin-config-writer.php`

Expected: FAIL until defaults are updated.

- [ ] **Step 3: Implement default and UI placement**

Set ConfigLoader and `config.example.php` defaults to true. In admin forms, render Public read all buckets before Advanced. In `AdminConfigWriter`, default absent public-read input to true unless an existing config is being edited. In `AdminRouter::defaultValues()`, set `public_read_all_buckets` true.

- [ ] **Step 4: Verify**

Run: `composer check`

Expected: lint, integration tests, and release archive tests pass.

## Self-Review Notes

- Spec coverage: route model, installer, admin dashboard, config editing, auth/session/CSRF, error handling, tests, and docs are covered by Tasks 1-8.
- Scope: no database, metrics, object browser, roles, or JS framework are included.
- Consistency: class names and paths use the `MiniS3\Admin` namespace and are wired into `public/index.php` before S3 routing.
