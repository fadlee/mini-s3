# Admin Check Update Button Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dashboard `Check update` button that refreshes cached GitHub update status on demand.

**Architecture:** Keep update fetching inside `AdminUpgradeService`. Add cache read/write helpers under `<DATA_DIR>/.upgrade-cache/latest.json`, use cached status for normal dashboard rendering, and force-refresh status from a CSRF-protected `POST /_/check-update` route. Render the check button in the existing Updates panel for generated release installs only.

**Tech Stack:** PHP 8.0+, plain PHP sessions/CSRF, filesystem JSON cache, existing PHP unit tests and shell integration checks.

---

## File Map

- Modify `src/Admin/AdminUpgradeService.php`: add cached update status with a 6-hour TTL and force-refresh support.
- Modify `tests/unit/admin-upgrade-service.php`: cover cache hit, stale cache refresh, forced refresh, and cache write behavior.
- Modify `src/Admin/AdminRenderer.php`: render `Check update` form/button for release installs.
- Modify `tests/unit/admin-renderer.php`: assert the button appears for release update states and not for source installs.
- Modify `src/Admin/AdminRouter.php`: use cached status on dashboard and add CSRF-protected `POST /_/check-update` route.
- Modify `tests/integration/run.sh`: assert unauthenticated check-update route is protected like upgrade route.

## Task 1: Cache Update Status In Upgrade Service

**Files:**
- Modify: `src/Admin/AdminUpgradeService.php`
- Modify: `tests/unit/admin-upgrade-service.php`

- [ ] **Step 1: Write failing cache tests**

Append this block before the final failure check in `tests/unit/admin-upgrade-service.php`:

```php
$cacheWorkspace = sys_get_temp_dir() . '/mini-s3-upgrade-cache-' . bin2hex(random_bytes(4));
$cacheDataDir = $cacheWorkspace . '/data';
mkdir($cacheDataDir, 0777, true);
$fetchCount = 0;
$cacheService = new AdminUpgradeService($cacheWorkspace, $cacheDataDir, $cacheWorkspace . '/index.php', function () use (&$fetchCount): array {
    $fetchCount++;
    return [
        'tag_name' => 'v1.0.2',
        'assets' => [
            ['name' => 'mini-s3-v1.0.2.zip', 'browser_download_url' => 'https://example.test/mini-s3-v1.0.2.zip'],
        ],
    ];
});
$cached = $cacheService->cachedStatus('v1.0.1');
assertSameValue('update_available', $cached['state'], 'first cached status fetches latest release');
assertSameValue(1, $fetchCount, 'first cached status calls fetcher');
$cachedAgain = $cacheService->cachedStatus('v1.0.1');
assertSameValue('update_available', $cachedAgain['state'], 'fresh cached status is reused');
assertSameValue(1, $fetchCount, 'fresh cached status does not call fetcher again');
$forced = $cacheService->cachedStatus('v1.0.1', true);
assertSameValue('update_available', $forced['state'], 'forced cached status refresh still returns update status');
assertSameValue(2, $fetchCount, 'forced cached status calls fetcher again');

$cacheFile = $cacheDataDir . '/.upgrade-cache/latest.json';
assertSameValue(true, is_file($cacheFile), 'cached status writes cache file');
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php tests/unit/admin-upgrade-service.php`

Expected: FAIL because `cachedStatus()` does not exist.

- [ ] **Step 3: Implement cached status**

In `src/Admin/AdminUpgradeService.php`, add this constant near `MAX_INDEX_BYTES`:

```php
    private const CACHE_TTL_SECONDS = 21600;
```

Add this public method after `checkLatest()`:

```php
    public function cachedStatus(string $currentVersion, bool $force = false): array
    {
        if (!$force) {
            $cached = $this->readCachedStatus();
            if ($cached !== null) {
                return $cached;
            }
        }

        $status = $this->checkLatest($currentVersion);
        $this->writeCachedStatus($status);

        return $status;
    }
```

Add these private helpers before `fetchLatestRelease()`:

```php
    private function readCachedStatus(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }
        $cachedAt = (int) ($decoded['cachedAt'] ?? 0);
        if ($cachedAt < time() - self::CACHE_TTL_SECONDS) {
            return null;
        }
        $status = $decoded['status'] ?? null;

        return is_array($status) ? $status : null;
    }

    private function writeCachedStatus(array $status): void
    {
        $dir = dirname($this->cachePath());
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            return;
        }
        if (!is_writable($dir)) {
            return;
        }
        file_put_contents($this->cachePath(), json_encode([
            'cachedAt' => time(),
            'status' => $status,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function cachePath(): string
    {
        return $this->dataDir . '/.upgrade-cache/latest.json';
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
git commit -m "feat: cache admin update checks"
```

## Task 2: Render Check Update Button

**Files:**
- Modify: `src/Admin/AdminRenderer.php`
- Modify: `tests/unit/admin-renderer.php`

- [ ] **Step 1: Write failing renderer assertions**

In `tests/unit/admin-renderer.php`, add these assertions after the existing upgrade form assertion:

```php
assertContainsText('Check update', $html, 'check update button is rendered for release installs');
assertContainsText('action="/_/check-update"', $html, 'check update form posts to check route');
```

Add this assertion after the unavailable source-install assertions:

```php
assertNotContainsText('Check update', $unavailableHtml, 'source install has no check update button');
```

- [ ] **Step 2: Run renderer test to verify it fails**

Run: `php tests/unit/admin-renderer.php`

Expected: FAIL because the check update button is missing.

- [ ] **Step 3: Render check update form**

In `src/Admin/AdminRenderer.php`, inside `updatesPanel()`, add this after latest/current version rendering and before the upgrade form block:

```php
        if ($state !== 'unavailable') {
            $body .= '<form method="post" action="/_/check-update">'
                . '<input type="hidden" name="csrf_token" value="' . $this->e((string) ($status['csrfToken'] ?? '')) . '">'
                . '<button type="submit">Check update</button>'
                . '</form>';
        }
```

- [ ] **Step 4: Run targeted tests**

Run: `php tests/unit/admin-renderer.php`

Expected: PASS.

Run: `tests/lint.sh`

Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/AdminRenderer.php tests/unit/admin-renderer.php
git commit -m "feat: render check update button"
```

## Task 3: Wire Check Update Route

**Files:**
- Modify: `src/Admin/AdminRouter.php`
- Modify: `tests/integration/run.sh`

- [ ] **Step 1: Add route protection integration assertion**

In `tests/integration/run.sh`, add this after the unauthenticated upgrade route assertion:

```bash
run_request POST "/_/check-update" "" "$TMP_DIR/admin-check-update-unauth.body" "$TMP_DIR/admin-check-update-unauth.meta" "Host: $SIGN_HOST"
assert_eq "200" "$(meta_status "$TMP_DIR/admin-check-update-unauth.meta")" "Unauthenticated check-update route should render login page"
assert_contains "Mini S3 Admin Login" "$TMP_DIR/admin-check-update-unauth.body" "Unauthenticated check-update route should be protected"
```

- [ ] **Step 2: Run integration test to verify it fails**

Run: `tests/integration/run.sh`

Expected: FAIL until the route is handled before login POST processing.

- [ ] **Step 3: Use cached status and add forced check route**

In `src/Admin/AdminRouter.php`, change the unauthenticated route guard from:

```php
            if ($path === '/_/upgrade' && !$auth->isAuthenticated()) {
```

to:

```php
            if (in_array($path, ['/_/upgrade', '/_/check-update'], true) && !$auth->isAuthenticated()) {
```

Add this after the `/_/upgrade` authenticated route block:

```php
            if ($path === '/_/check-update') {
                $this->handleCheckUpdate($auth, $config);
            }
```

Change dashboard status calculation from:

```php
                : $upgradeService->checkLatest($currentVersion);
```

to:

```php
                : $upgradeService->cachedStatus($currentVersion);
```

Add this private method before `handleUpgrade()`:

```php
    private function handleCheckUpdate(AdminAuth $auth, array $config): never
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

        $status = $this->upgradeService($config)->cachedStatus($currentVersion, true);
        $auth->setFlash((string) $status['message']);
        $this->redirect('/_');
    }
```

- [ ] **Step 4: Run targeted checks**

Run: `tests/integration/run.sh`

Expected: PASS.

Run: `tests/lint.sh`

Expected: no syntax errors.

- [ ] **Step 5: Commit**

```bash
git add src/Admin/AdminRouter.php tests/integration/run.sh
git commit -m "feat: wire check update route"
```

## Task 4: Final Verification

**Files:**
- Verify only unless fixes are needed.

- [ ] **Step 1: Run full project checks**

Run: `composer check`

Expected: lint passes, integration tests pass, release archive test passes.

- [ ] **Step 2: Inspect git status**

Run: `git status --short --branch`

Expected: `main` may be ahead of `origin/main`; local untracked `config/` may remain and must not be committed.

- [ ] **Step 3: Push if requested**

If the user asked to publish this change, run:

```bash
git push origin main
```

## Self-Review Notes

- Spec coverage: the plan covers cached dashboard status, a forced `Check update` route, release-install-only button rendering, source-install no-GitHub behavior, error cache persistence, and no JavaScript/AJAX.
- Scope: no configurable TTL, scheduler, or cache management UI is included.
- Type consistency: status arrays remain the same shape: `state`, `message`, `currentVersion`, `latestVersion`, `assetUrl`, optional `csrfToken`.
