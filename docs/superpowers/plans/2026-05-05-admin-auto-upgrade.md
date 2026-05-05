# Admin Auto-Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an admin dashboard update panel and one-click upgrade path for generated single-file Mini S3 release installs.

**Architecture:** Keep release installs distinguishable by embedding `MINI_S3_VERSION` in generated `index.php`. Add a focused `AdminUpgradeService` that checks official GitHub releases, validates a downloaded release `index.php`, backs up the current entry file, and swaps only `index.php`. Wire the service into the existing authenticated admin router and renderer with session flash messages.

**Tech Stack:** PHP 8.0+, plain PHP sessions, PHP stream functions, `ZipArchive`, existing shell release builder, existing shell/PHP tests.

---

## File Map

- Modify `scripts/build-release.sh`: embed `MINI_S3_VERSION` in generated release `index.php` and include the new upgrade service in the bundle source order.
- Modify `tests/release-archive.sh`: assert generated `index.php` contains the version constant.
- Create `src/Admin/AdminUpgradeService.php`: update check, release metadata parsing, asset selection, file validation, backup, install, rollback, and temp cleanup.
- Create `tests/unit/admin-upgrade-service.php`: unit coverage for version comparison, source-install unavailable state, metadata parsing, asset selection, validation, and install/rollback behavior without real GitHub calls.
- Modify `src/Admin/AdminRenderer.php`: render update states and flash messages on the dashboard.
- Modify `tests/unit/admin-renderer.php`: assert dashboard update panel states.
- Modify `src/Admin/AdminAuth.php`: add small session flash helpers for admin status messages.
- Modify `tests/unit/admin-auth.php`: cover flash message set/consume semantics.
- Modify `src/Admin/AdminRouter.php`: instantiate upgrade service after login, route `POST /_/upgrade`, enforce CSRF, use flash messages, and pass update status to dashboard.
- Modify `public/index.php`: require `src/Admin/AdminUpgradeService.php` for source installs.
- Modify `tests/lint.sh`: include `tests/unit/admin-upgrade-service.php`.
- Modify `tests/integration/run.sh`: add minimal `/_/upgrade` unauthenticated/authenticated-CSRF behavior checks if the existing harness can do so cleanly.

## Task 1: Embed Release Version Constant

**Files:**
- Modify: `scripts/build-release.sh`
- Modify: `tests/release-archive.sh`

- [ ] **Step 1: Write the failing archive assertion**

In `tests/release-archive.sh`, add this check after line 74 where the extracted `index.php` is linted:

```bash
if ! grep -Fq "define('MINI_S3_VERSION', '$VERSION');" "$TMP_DIR/mini-s3-$VERSION/index.php"; then
  fail "generated index.php should define MINI_S3_VERSION"
fi
```

- [ ] **Step 2: Run release archive test to verify it fails**

Run: `tests/release-archive.sh`

Expected: FAIL with `generated index.php should define MINI_S3_VERSION`.

- [ ] **Step 3: Embed the version constant in generated releases**

In `scripts/build-release.sh`, update the generated global namespace block around lines 94-96 from:

```bash
  printf "namespace {\n"
  printf "    define('BASE_PATH', __DIR__);\n"
  printf "}\n\n"
```

to:

```bash
  printf "namespace {\n"
  printf "    define('BASE_PATH', __DIR__);\n"
  printf "    define('MINI_S3_VERSION', '%s');\n" "$VERSION"
  printf "}\n\n"
```

- [ ] **Step 4: Run release archive test to verify it passes**

Run: `tests/release-archive.sh`

Expected: PASS and output includes `[PASS] Release archive test passed`.

- [ ] **Step 5: Commit**

```bash
git add scripts/build-release.sh tests/release-archive.sh
git commit -m "build: embed release version in bundle"
```

## Task 2: Add Upgrade Service Core Status Logic

**Files:**
- Create: `src/Admin/AdminUpgradeService.php`
- Create: `tests/unit/admin-upgrade-service.php`
- Modify: `tests/lint.sh`
- Modify: `public/index.php`
- Modify: `scripts/build-release.sh`

- [ ] **Step 1: Register the future test file in lint**

In `tests/lint.sh`, add this entry near the other admin unit tests:

```bash
  "$ROOT/tests/unit/admin-upgrade-service.php"
```

- [ ] **Step 2: Write failing tests for status and helper logic**

Create `tests/unit/admin-upgrade-service.php` with:

```php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminUpgradeService.php';

use MiniS3\Admin\AdminUpgradeService;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

function assertContainsValue(string $needle, string $haystack, string $message): void
{
    global $failures;
    if (!str_contains($haystack, $needle)) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: missing {$needle}" . PHP_EOL);
    }
}

$service = new AdminUpgradeService(__DIR__, sys_get_temp_dir(), __DIR__ . '/index.php');

assertSameValue(0, $service->compareVersions('v1.0.0', '1.0.0'), 'same version with v prefix compares equal');
assertSameValue(-1, $service->compareVersions('v1.0.0', 'v1.0.1'), 'older current version compares lower');
assertSameValue(1, $service->compareVersions('v1.2.0', 'v1.1.9'), 'newer current version compares higher');

$sourceStatus = $service->status(null);
assertSameValue('unavailable', $sourceStatus['state'], 'source install status is unavailable');
assertContainsValue('release installs', $sourceStatus['message'], 'source install explains release-only upgrade');

$metadata = [
    'tag_name' => 'v1.0.2',
    'assets' => [
        ['name' => 'notes.txt', 'browser_download_url' => 'https://example.test/notes.txt'],
        ['name' => 'mini-s3-v1.0.2.zip', 'browser_download_url' => 'https://example.test/mini-s3-v1.0.2.zip'],
    ],
];

assertSameValue('v1.0.2', $service->releaseTag($metadata), 'release tag is parsed');
assertSameValue('https://example.test/mini-s3-v1.0.2.zip', $service->assetUrl($metadata, 'v1.0.2'), 'expected zip asset is selected');
assertSameValue(null, $service->assetUrl($metadata, 'v1.0.3'), 'unexpected zip asset is rejected');

$validCode = "<?php\n\ndefine('MINI_S3_VERSION', 'v1.0.2');\nclass AdminRouter {}\nclass S3Router {}\n";
$validation = $service->validateReleaseIndex($validCode, 'v1.0.2');
assertSameValue(true, $validation['valid'], 'valid release index is accepted');

$validation = $service->validateReleaseIndex('plain text', 'v1.0.2');
assertSameValue(false, $validation['valid'], 'non-php release index is rejected');

$validation = $service->validateReleaseIndex("<?php\nclass AdminRouter {}\nclass S3Router {}\n", 'v1.0.2');
assertSameValue(false, $validation['valid'], 'missing version constant is rejected');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminUpgradeService tests passed" . PHP_EOL;
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `php tests/unit/admin-upgrade-service.php`

Expected: FAIL because `src/Admin/AdminUpgradeService.php` does not exist.

- [ ] **Step 4: Create minimal service with pure helper/status methods**

Create `src/Admin/AdminUpgradeService.php` with:

```php
<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminUpgradeService
{
    private const REPO_OWNER = 'fadlee';
    private const REPO_NAME = 'mini-s3';
    private const MAX_INDEX_BYTES = 5242880;

    public function __construct(
        private readonly string $baseDir,
        private readonly string $dataDir,
        private readonly string $entryFile
    ) {
    }

    public function status(?string $currentVersion): array
    {
        if ($currentVersion === null || $currentVersion === '') {
            return [
                'state' => 'unavailable',
                'message' => 'Auto-upgrade is only available for generated release installs.',
                'currentVersion' => null,
                'latestVersion' => null,
                'assetUrl' => null,
            ];
        }

        return [
            'state' => 'unknown',
            'message' => 'Update check has not run yet.',
            'currentVersion' => $currentVersion,
            'latestVersion' => null,
            'assetUrl' => null,
        ];
    }

    public function compareVersions(string $current, string $latest): int
    {
        return version_compare($this->normalizeVersion($current), $this->normalizeVersion($latest));
    }

    public function releaseTag(array $metadata): ?string
    {
        $tag = (string) ($metadata['tag_name'] ?? '');
        if (!preg_match('/^v?\d+\.\d+\.\d+$/', $tag)) {
            return null;
        }

        return str_starts_with($tag, 'v') ? $tag : 'v' . $tag;
    }

    public function assetUrl(array $metadata, string $tag): ?string
    {
        $expectedName = 'mini-s3-' . $tag . '.zip';
        foreach ((array) ($metadata['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['name'] ?? '') !== $expectedName) {
                continue;
            }
            $url = (string) ($asset['browser_download_url'] ?? '');
            return $url === '' ? null : $url;
        }

        return null;
    }

    public function validateReleaseIndex(string $code, string $expectedVersion): array
    {
        if ($code === '' || strlen($code) > self::MAX_INDEX_BYTES) {
            return ['valid' => false, 'message' => 'Release index.php has an invalid size.'];
        }
        if (!str_starts_with($code, '<?php')) {
            return ['valid' => false, 'message' => 'Release index.php is not a PHP file.'];
        }
        if (!str_contains($code, "define('MINI_S3_VERSION', '" . $expectedVersion . "');")) {
            return ['valid' => false, 'message' => 'Release index.php does not match the expected version.'];
        }
        if (!str_contains($code, 'AdminRouter') || !str_contains($code, 'S3Router')) {
            return ['valid' => false, 'message' => 'Release index.php does not look like a Mini S3 runtime file.'];
        }

        return ['valid' => true, 'message' => 'Release index.php is valid.'];
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }
}
```

- [ ] **Step 5: Require the service from source and release builds**

In `public/index.php`, add this require after `AdminStats.php`:

```php
require_once BASE_PATH . '/src/Admin/AdminUpgradeService.php';
```

In `scripts/build-release.sh`, add this source file after `src/Admin/AdminStats.php`:

```bash
  "src/Admin/AdminUpgradeService.php"
```

- [ ] **Step 6: Run targeted tests**

Run: `php tests/unit/admin-upgrade-service.php`

Expected: PASS with `[PASS] AdminUpgradeService tests passed`.

Run: `tests/lint.sh`

Expected: no syntax errors for all listed files.

- [ ] **Step 7: Commit**

```bash
git add src/Admin/AdminUpgradeService.php public/index.php scripts/build-release.sh tests/lint.sh tests/unit/admin-upgrade-service.php
git commit -m "feat: add admin upgrade service status helpers"
```

## Task 3: Add GitHub Update Check

**Files:**
- Modify: `src/Admin/AdminUpgradeService.php`
- Modify: `tests/unit/admin-upgrade-service.php`

- [ ] **Step 1: Extend tests with injectable GitHub metadata fetcher**

In `tests/unit/admin-upgrade-service.php`, after the existing `$service = ...` line, add:

```php
$upToDateService = new AdminUpgradeService(__DIR__, sys_get_temp_dir(), __DIR__ . '/index.php', function (): array {
    return [
        'tag_name' => 'v1.0.1',
        'assets' => [
            ['name' => 'mini-s3-v1.0.1.zip', 'browser_download_url' => 'https://example.test/mini-s3-v1.0.1.zip'],
        ],
    ];
});
$upToDate = $upToDateService->checkLatest('v1.0.1');
assertSameValue('up_to_date', $upToDate['state'], 'matching latest release is up to date');
assertSameValue('v1.0.1', $upToDate['latestVersion'], 'latest version is included for up to date state');

$updateService = new AdminUpgradeService(__DIR__, sys_get_temp_dir(), __DIR__ . '/index.php', function (): array {
    return [
        'tag_name' => 'v1.0.2',
        'assets' => [
            ['name' => 'mini-s3-v1.0.2.zip', 'browser_download_url' => 'https://example.test/mini-s3-v1.0.2.zip'],
        ],
    ];
});
$update = $updateService->checkLatest('v1.0.1');
assertSameValue('update_available', $update['state'], 'newer latest release is available');
assertSameValue('v1.0.2', $update['latestVersion'], 'latest version is included for available update');
assertSameValue('https://example.test/mini-s3-v1.0.2.zip', $update['assetUrl'], 'asset url is included for available update');

$errorService = new AdminUpgradeService(__DIR__, sys_get_temp_dir(), __DIR__ . '/index.php', function (): array {
    return ['tag_name' => 'latest', 'assets' => []];
});
$error = $errorService->checkLatest('v1.0.1');
assertSameValue('error', $error['state'], 'invalid release tag returns error state');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/unit/admin-upgrade-service.php`

Expected: FAIL because constructor does not accept a fetcher and `checkLatest()` does not exist.

- [ ] **Step 3: Add injectable metadata fetcher and update check**

Update the constructor and add `checkLatest()` plus `fetchLatestRelease()` in `src/Admin/AdminUpgradeService.php`:

```php
    public function __construct(
        private readonly string $baseDir,
        private readonly string $dataDir,
        private readonly string $entryFile,
        private readonly mixed $metadataFetcher = null
    ) {
    }

    public function checkLatest(string $currentVersion): array
    {
        try {
            $metadata = $this->metadataFetcher === null
                ? $this->fetchLatestRelease()
                : ($this->metadataFetcher)();
        } catch (\Throwable $e) {
            return [
                'state' => 'error',
                'message' => 'Unable to check GitHub releases: ' . $e->getMessage(),
                'currentVersion' => $currentVersion,
                'latestVersion' => null,
                'assetUrl' => null,
            ];
        }

        $latestTag = $this->releaseTag($metadata);
        if ($latestTag === null) {
            return [
                'state' => 'error',
                'message' => 'Latest GitHub release does not have a valid version tag.',
                'currentVersion' => $currentVersion,
                'latestVersion' => null,
                'assetUrl' => null,
            ];
        }

        if ($this->compareVersions($currentVersion, $latestTag) >= 0) {
            return [
                'state' => 'up_to_date',
                'message' => 'Mini S3 is up to date.',
                'currentVersion' => $currentVersion,
                'latestVersion' => $latestTag,
                'assetUrl' => null,
            ];
        }

        $assetUrl = $this->assetUrl($metadata, $latestTag);
        if ($assetUrl === null) {
            return [
                'state' => 'error',
                'message' => 'Latest GitHub release does not include the expected zip asset.',
                'currentVersion' => $currentVersion,
                'latestVersion' => $latestTag,
                'assetUrl' => null,
            ];
        }

        return [
            'state' => 'update_available',
            'message' => 'Update available: ' . $latestTag,
            'currentVersion' => $currentVersion,
            'latestVersion' => $latestTag,
            'assetUrl' => $assetUrl,
        ];
    }

    private function fetchLatestRelease(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: mini-s3-admin-upgrade\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 5,
            ],
        ]);
        $url = 'https://api.github.com/repos/' . self::REPO_OWNER . '/' . self::REPO_NAME . '/releases/latest';
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('request failed');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('invalid JSON response');
        }

        return $decoded;
    }
```

- [ ] **Step 4: Run targeted tests**

Run: `php tests/unit/admin-upgrade-service.php`

Expected: PASS.

Run: `tests/lint.sh`

Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/AdminUpgradeService.php tests/unit/admin-upgrade-service.php
git commit -m "feat: check latest admin upgrade release"
```

## Task 4: Add Download, Extract, Validate, Backup, Install

**Files:**
- Modify: `src/Admin/AdminUpgradeService.php`
- Modify: `tests/unit/admin-upgrade-service.php`

- [ ] **Step 1: Add install tests with local zip and temp files**

Append this block before the final failure check in `tests/unit/admin-upgrade-service.php`:

```php
$workspace = sys_get_temp_dir() . '/mini-s3-upgrade-' . bin2hex(random_bytes(4));
$baseDir = $workspace . '/app';
$dataDir = $baseDir . '/data';
mkdir($dataDir, 0777, true);
$entryFile = $baseDir . '/index.php';
file_put_contents($entryFile, "<?php\ndefine('MINI_S3_VERSION', 'v1.0.1');\nclass AdminRouter {}\nclass S3Router {}\n");

$zipPath = $workspace . '/mini-s3-v1.0.2.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);
$zip->addFromString('mini-s3-v1.0.2/index.php', "<?php\ndefine('MINI_S3_VERSION', 'v1.0.2');\nclass AdminRouter {}\nclass S3Router {}\n");
$zip->addFromString('mini-s3-v1.0.2/.htaccess', 'ignored');
$zip->close();

$installService = new AdminUpgradeService($baseDir, $dataDir, $entryFile, null, function (string $url, string $destination) use ($zipPath): void {
    copy($zipPath, $destination);
});
$result = $installService->upgrade('v1.0.1', 'v1.0.2', 'https://example.test/mini-s3-v1.0.2.zip');
assertSameValue(true, $result['ok'], 'upgrade succeeds');
assertContainsValue("define('MINI_S3_VERSION', 'v1.0.2');", (string) file_get_contents($entryFile), 'entry file is replaced with new version');
assertSameValue(false, str_contains((string) file_get_contents($entryFile), 'ignored'), 'htaccess content is not written into entry file');
$backups = glob($dataDir . '/.upgrade-backups/*/index.php') ?: [];
assertSameValue(1, count($backups), 'old index.php backup is created');
assertContainsValue("define('MINI_S3_VERSION', 'v1.0.1');", (string) file_get_contents($backups[0]), 'backup contains old version');

$badZipPath = $workspace . '/mini-s3-v1.0.3.zip';
$badZip = new ZipArchive();
$badZip->open($badZipPath, ZipArchive::CREATE);
$badZip->addFromString('mini-s3-v1.0.3/../index.php', 'bad');
$badZip->close();
$badService = new AdminUpgradeService($baseDir, $dataDir, $entryFile, null, function (string $url, string $destination) use ($badZipPath): void {
    copy($badZipPath, $destination);
});
$badResult = $badService->upgrade('v1.0.2', 'v1.0.3', 'https://example.test/mini-s3-v1.0.3.zip');
assertSameValue(false, $badResult['ok'], 'upgrade rejects archive without exact index path');
assertContainsValue("define('MINI_S3_VERSION', 'v1.0.2');", (string) file_get_contents($entryFile), 'entry file remains unchanged after rejected archive');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/unit/admin-upgrade-service.php`

Expected: FAIL because constructor does not accept a downloader and `upgrade()` does not exist.

- [ ] **Step 3: Add downloader injection and upgrade implementation**

Update the constructor signature in `src/Admin/AdminUpgradeService.php` to:

```php
    public function __construct(
        private readonly string $baseDir,
        private readonly string $dataDir,
        private readonly string $entryFile,
        private readonly mixed $metadataFetcher = null,
        private readonly mixed $downloader = null
    ) {
    }
```

Then add these methods before `normalizeVersion()`:

```php
    public function upgrade(string $currentVersion, string $latestVersion, string $assetUrl): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'message' => 'ZipArchive extension is required for auto-upgrade.'];
        }
        if ($this->compareVersions($currentVersion, $latestVersion) >= 0) {
            return ['ok' => false, 'message' => 'No newer version is available.'];
        }
        if (!is_file($this->entryFile) || !is_writable($this->entryFile) || !is_writable(dirname($this->entryFile))) {
            return ['ok' => false, 'message' => 'Current index.php or its directory is not writable.'];
        }

        $tmpDir = $this->dataDir . '/.upgrade-tmp';
        if (!$this->ensureDirectory($tmpDir)) {
            return ['ok' => false, 'message' => 'Temporary upgrade directory cannot be created or written.'];
        }

        $zipPath = $tmpDir . '/mini-s3-' . $latestVersion . '.zip';
        $newPath = $tmpDir . '/index-' . $latestVersion . '.php';

        try {
            $this->download($assetUrl, $zipPath);
            $code = $this->extractIndex($zipPath, $latestVersion);
            $validation = $this->validateReleaseIndex($code, $latestVersion);
            if (!$validation['valid']) {
                return ['ok' => false, 'message' => $validation['message']];
            }
            if (file_put_contents($newPath, $code) === false) {
                return ['ok' => false, 'message' => 'Unable to write downloaded index.php.'];
            }

            $backupPath = $this->backupCurrentIndex();
            $siblingNewPath = $this->entryFile . '.new';
            if (!copy($newPath, $siblingNewPath)) {
                return ['ok' => false, 'message' => 'Unable to stage new index.php next to current entry file.'];
            }
            if (!rename($siblingNewPath, $this->entryFile)) {
                @unlink($siblingNewPath);
                $restored = @copy($backupPath, $this->entryFile);
                return ['ok' => false, 'message' => $restored ? 'Upgrade failed and rollback succeeded.' : 'Upgrade failed and rollback failed. Restore backup manually.'];
            }

            return ['ok' => true, 'message' => 'Mini S3 upgraded to ' . $latestVersion . '.', 'backupPath' => $backupPath];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            @unlink($zipPath);
            @unlink($newPath);
        }
    }

    private function download(string $url, string $destination): void
    {
        if ($this->downloader !== null) {
            ($this->downloader)($url, $destination);
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: mini-s3-admin-upgrade\r\n",
                'timeout' => 30,
            ],
        ]);
        $contents = file_get_contents($url, false, $context);
        if ($contents === false) {
            throw new \RuntimeException('Download failed.');
        }
        if (file_put_contents($destination, $contents) === false) {
            throw new \RuntimeException('Unable to save downloaded release asset.');
        }
    }

    private function extractIndex(string $zipPath, string $version): string
    {
        $expectedPath = 'mini-s3-' . $version . '/index.php';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open release zip.');
        }
        $code = $zip->getFromName($expectedPath);
        $zip->close();
        if ($code === false) {
            throw new \RuntimeException('Release zip does not contain the expected index.php.');
        }

        return $code;
    }

    private function backupCurrentIndex(): string
    {
        $backupDir = $this->dataDir . '/.upgrade-backups/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!$this->ensureDirectory($backupDir)) {
            throw new \RuntimeException('Backup directory is not writable.');
        }
        $backupPath = $backupDir . '/index.php';
        if (!copy($this->entryFile, $backupPath)) {
            throw new \RuntimeException('Unable to back up current index.php.');
        }

        return $backupPath;
    }

    private function ensureDirectory(string $path): bool
    {
        if (!is_dir($path) && !mkdir($path, 0777, true)) {
            return false;
        }

        return is_writable($path);
    }
```

- [ ] **Step 4: Run targeted tests**

Run: `php tests/unit/admin-upgrade-service.php`

Expected: PASS.

Run: `tests/lint.sh`

Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/AdminUpgradeService.php tests/unit/admin-upgrade-service.php
git commit -m "feat: install single-file admin upgrades"
```

## Task 5: Render Dashboard Update Panel

**Files:**
- Modify: `src/Admin/AdminRenderer.php`
- Modify: `tests/unit/admin-renderer.php`

- [ ] **Step 1: Add renderer tests for update states and flash messages**

In `tests/unit/admin-renderer.php`, replace the dashboard call with this signature:

```php
$html = (new AdminRenderer())->dashboard([
    'data_dir' => '/tmp/mini-s3-data',
    'status' => 'ok',
    'bucket_count' => 1,
    'object_count' => 2,
    'total_bytes' => 3,
], [
    'CREDENTIALS' => ['client-key' => 'client-secret'],
], 'https://s3.example.test', [
    'state' => 'update_available',
    'message' => 'Update available: v1.0.2',
    'currentVersion' => 'v1.0.1',
    'latestVersion' => 'v1.0.2',
    'assetUrl' => 'https://example.test/mini-s3-v1.0.2.zip',
], 'Upgrade ready');
```

Then add these assertions after the existing connection config assertions:

```php
assertContainsText('Updates', $html, 'dashboard shows updates panel');
assertContainsText('Current version: v1.0.1', $html, 'current version is rendered');
assertContainsText('Latest version: v1.0.2', $html, 'latest version is rendered');
assertContainsText('Upgrade to v1.0.2', $html, 'upgrade button is rendered');
assertContainsText('action="/_/upgrade"', $html, 'upgrade form posts to upgrade route');
assertContainsText('Upgrade ready', $html, 'flash message is rendered');

$unavailableHtml = (new AdminRenderer())->dashboard([
    'data_dir' => '/tmp/mini-s3-data',
    'status' => 'ok',
    'bucket_count' => 0,
    'object_count' => 0,
    'total_bytes' => 0,
], [], '', [
    'state' => 'unavailable',
    'message' => 'Auto-upgrade is only available for generated release installs.',
    'currentVersion' => null,
    'latestVersion' => null,
    'assetUrl' => null,
], '');
assertContainsText('Auto-upgrade is only available for generated release installs.', $unavailableHtml, 'unavailable update state is rendered');
assertNotContainsText('Upgrade to', $unavailableHtml, 'unavailable state has no upgrade button');
```

- [ ] **Step 2: Run renderer test to verify it fails**

Run: `php tests/unit/admin-renderer.php`

Expected: FAIL because `dashboard()` does not accept update status or render update UI.

- [ ] **Step 3: Update dashboard signature and render update panel**

In `src/Admin/AdminRenderer.php`, change `dashboard()` signature to:

```php
    public function dashboard(array $stats, array $config = [], string $endpoint = '', array $updateStatus = [], string $flashMessage = ''): string
```

Change the `$body` composition to include flash and update panel:

```php
        $body = $this->flashMessage($flashMessage)
            . '<div class="cards">'
            . $this->statCard('Buckets', (string) $stats['bucket_count'])
            . $this->statCard('Objects', (string) $stats['object_count'])
            . $this->statCard('Storage', $this->formatBytes((int) $stats['total_bytes']))
            . $this->statCard('Data Dir', $this->e((string) $stats['status']))
            . '</div>'
            . '<section class="panel"><h2>Data directory</h2><code>' . $this->e((string) $stats['data_dir']) . '</code></section>'
            . $this->updatesPanel($updateStatus)
            . $this->connectionConfig($config, $endpoint);
```

Add these private methods before `connectionConfig()`:

```php
    private function flashMessage(string $message): string
    {
        return $message === '' ? '' : '<div class="notice">' . $this->e($message) . '</div>';
    }

    private function updatesPanel(array $status): string
    {
        if ($status === []) {
            return '';
        }

        $state = (string) ($status['state'] ?? 'unknown');
        $message = (string) ($status['message'] ?? 'Update status unavailable.');
        $current = $status['currentVersion'] ?? null;
        $latest = $status['latestVersion'] ?? null;
        $body = '<p>' . $this->e($message) . '</p>';
        if (is_string($current) && $current !== '') {
            $body .= '<p><strong>Current version:</strong> ' . $this->e($current) . '</p>';
        }
        if (is_string($latest) && $latest !== '') {
            $body .= '<p><strong>Latest version:</strong> ' . $this->e($latest) . '</p>';
        }
        if ($state === 'update_available' && is_string($latest) && $latest !== '') {
            $body .= '<form method="post" action="/_/upgrade">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e((string) ($status['csrfToken'] ?? '')) . '">'
                . '<input type="hidden" name="latest_version" value="' . $this->e($latest) . '">'
                . '<input type="hidden" name="asset_url" value="' . $this->e((string) ($status['assetUrl'] ?? '')) . '">'
                . '<button type="submit">Upgrade to ' . $this->e($latest) . '</button>'
                . '</form>';
        }

        return '<section class="panel"><h2>Updates</h2>' . $body . '</section>';
    }
```

Update the CSS string in `layout()` to include `.notice` next to `.error` styling:

```css
.notice{background:#dcfce7;border:1px solid #86efac;color:#166534;padding:10px;border-radius:8px;margin-bottom:10px}
```

- [ ] **Step 4: Run renderer test**

Run: `php tests/unit/admin-renderer.php`

Expected: PASS.

Run: `tests/lint.sh`

Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/AdminRenderer.php tests/unit/admin-renderer.php
git commit -m "feat: render admin update panel"
```

## Task 6: Add Admin Flash Messages

**Files:**
- Modify: `src/Admin/AdminAuth.php`
- Modify: `tests/unit/admin-auth.php`

- [ ] **Step 1: Inspect current auth test**

Read `tests/unit/admin-auth.php` and preserve all existing assertions.

- [ ] **Step 2: Add failing flash tests**

Append these assertions before the final failure check in `tests/unit/admin-auth.php`:

```php
$auth->setFlash('Upgrade complete');
check($auth->consumeFlash() === 'Upgrade complete', 'flash message is consumed');
check($auth->consumeFlash() === '', 'flash message is cleared after consume');
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php tests/unit/admin-auth.php`

Expected: FAIL because `setFlash()` does not exist.

- [ ] **Step 4: Add flash helpers**

In `src/Admin/AdminAuth.php`, add this constant near the existing keys:

```php
    private const FLASH_KEY = 'mini_s3_admin_flash';
```

Update `logout()` to unset the flash key too:

```php
        unset($_SESSION[self::AUTH_KEY], $_SESSION[self::CSRF_KEY], $_SESSION[self::FLASH_KEY]);
```

Add these methods before `csrfToken()`:

```php
    public function setFlash(string $message): void
    {
        $_SESSION[self::FLASH_KEY] = $message;
    }

    public function consumeFlash(): string
    {
        $message = (string) ($_SESSION[self::FLASH_KEY] ?? '');
        unset($_SESSION[self::FLASH_KEY]);

        return $message;
    }
```

- [ ] **Step 5: Run auth test**

Run: `php tests/unit/admin-auth.php`

Expected: PASS.

Run: `tests/lint.sh`

Expected: no syntax errors.

- [ ] **Step 6: Commit**

```bash
git add src/Admin/AdminAuth.php tests/unit/admin-auth.php
git commit -m "feat: add admin flash messages"
```

## Task 7: Wire Upgrade Route And Dashboard Status

**Files:**
- Modify: `src/Admin/AdminRouter.php`
- Modify: `tests/integration/run.sh`

- [ ] **Step 1: Add integration expectation for protected upgrade route**

In `tests/integration/run.sh`, add a check near existing admin route checks that performs unauthenticated `POST /_/upgrade` and expects the login page or `401/200` behavior matching existing admin login protection. If the harness has a helper for HTTP requests, use it. If not, add this minimal curl-style check matching current patterns:

```bash
UPGRADE_UNAUTH_STATUS=$(php "$ROOT/tests/integration/request.php" POST '/_/upgrade' '' | awk 'NR==1 {print $2}')
if [ "$UPGRADE_UNAUTH_STATUS" != "200" ] && [ "$UPGRADE_UNAUTH_STATUS" != "401" ]; then
  fail "Unauthenticated upgrade route should be protected"
fi
```

If `request.php` output format differs, adapt this exact assertion to the harness format while keeping the intent: unauthenticated upgrade must not run an upgrade and must not return a generic `500`.

- [ ] **Step 2: Run integration test to verify it fails or exposes missing route behavior**

Run: `tests/integration/run.sh`

Expected: FAIL if the new route check does not match current behavior, or PASS if current login handling already protects it. Continue because production route still needs explicit upgrade behavior.

- [ ] **Step 3: Wire service in router dashboard and route**

In `src/Admin/AdminRouter.php`, after `$auth` is authenticated and after config handling, create update status:

```php
            if ($path === '/_/upgrade') {
                $this->handleUpgrade($auth, $config);
            }

            $upgradeService = $this->upgradeService($config);
            $currentVersion = defined('MINI_S3_VERSION') ? (string) constant('MINI_S3_VERSION') : null;
            $updateStatus = $currentVersion === null
                ? $upgradeService->status(null)
                : $upgradeService->checkLatest($currentVersion);
            $updateStatus['csrfToken'] = $auth->csrfToken();

            $stats = (new AdminStats())->scan((string) $config['DATA_DIR']);
            $this->html($renderer->dashboard($stats, $config, $this->endpoint(), $updateStatus, $auth->consumeFlash()));
```

Replace the old dashboard block:

```php
            $stats = (new AdminStats())->scan((string) $config['DATA_DIR']);
            $this->html($renderer->dashboard($stats, $config, $this->endpoint()));
```

Add these private methods before `defaultValues()`:

```php
    private function handleUpgrade(AdminAuth $auth, array $config): never
    {
        if ($this->method !== 'POST') {
            $this->redirect('/_');
        }
        if (!$auth->verifyCsrfToken((string) ($this->post['csrf_token'] ?? ''))) {
            $auth->setFlash('CSRF token is invalid.');
            $this->redirect('/_');
        }

        $currentVersion = defined('MINI_S3_VERSION') ? (string) constant('MINI_S3_VERSION') : null;
        if ($currentVersion === null) {
            $auth->setFlash('Auto-upgrade is only available for generated release installs.');
            $this->redirect('/_');
        }

        $latestVersion = (string) ($this->post['latest_version'] ?? '');
        $assetUrl = (string) ($this->post['asset_url'] ?? '');
        $result = $this->upgradeService($config)->upgrade($currentVersion, $latestVersion, $assetUrl);
        $auth->setFlash((string) $result['message']);
        $this->redirect('/_');
    }

    private function upgradeService(array $config): AdminUpgradeService
    {
        $entryFile = (string) ($_SERVER['SCRIPT_FILENAME'] ?? '');
        if ($entryFile === '') {
            $entryFile = $this->baseDir . '/index.php';
        }

        return new AdminUpgradeService($this->baseDir, (string) $config['DATA_DIR'], $entryFile);
    }
```

- [ ] **Step 4: Run targeted checks**

Run: `php -l src/Admin/AdminRouter.php`

Expected: no syntax errors.

Run: `tests/integration/run.sh`

Expected: PASS.

Run: `tests/lint.sh`

Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/AdminRouter.php tests/integration/run.sh
git commit -m "feat: wire admin upgrade route"
```

## Task 8: Final Release Verification

**Files:**
- Inspect/verify only unless fixes are needed.

- [ ] **Step 1: Run full project checks**

Run: `composer check`

Expected: lint passes, integration tests pass, release archive test passes.

- [ ] **Step 2: Verify generated release contains upgrade code and version**

Run: `scripts/build-release.sh v0.0.0-test`

Expected: creates `dist/mini-s3-v0.0.0-test.zip`.

Run: `unzip -p dist/mini-s3-v0.0.0-test.zip mini-s3-v0.0.0-test/index.php | grep -F "define('MINI_S3_VERSION', 'v0.0.0-test');"`

Expected: prints the version define line.

Run: `unzip -p dist/mini-s3-v0.0.0-test.zip mini-s3-v0.0.0-test/index.php | grep -F "AdminUpgradeService"`

Expected: prints at least one line containing `AdminUpgradeService`.

- [ ] **Step 3: Review git diff for scope**

Run: `git status --short`

Expected: only intended files are modified or untracked. Existing local `config/` may remain untracked and must not be committed.

Run: `git diff --stat`

Expected: changes are limited to admin upgrade service, admin UI/router/auth, release builder/tests, and integration/lint tests.

- [ ] **Step 4: Commit final fixes if any**

If Step 1 or Step 2 required fixes, commit them:

```bash
git add <fixed-files>
git commit -m "fix: complete admin auto upgrade checks"
```

If no fixes were needed, do not create an empty commit.

## Self-Review Notes

- Spec coverage: tasks cover generated `MINI_S3_VERSION`, source-install unavailable state without GitHub checks, GitHub headers/metadata parsing, semver comparison, expected asset selection, `ZipArchive` extraction, exact `index.php` validation, backup under `<DATA_DIR>/.upgrade-backups`, temp files under `<DATA_DIR>/.upgrade-tmp`, `SCRIPT_FILENAME` target resolution, CSRF-protected `/_/upgrade`, flash messages, `.htaccess` ignored, release archive verification, and no shell dependency in production code.
- Scope: the plan intentionally does not support source-install upgrades, arbitrary repos, checksum/signature validation, `.htaccess` replacement, Composer, migrations, background jobs, or progress streaming.
- Type consistency: update status arrays consistently use `state`, `message`, `currentVersion`, `latestVersion`, `assetUrl`, and optional `csrfToken`; upgrade result arrays use `ok`, `message`, and optional `backupPath`.
