<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminAssets.php';
require_once __DIR__ . '/../../src/Admin/AdminRenderer.php';

use MiniS3\Admin\AdminRenderer;

$failures = 0;

function assertContainsText(string $needle, string $haystack, string $message): void
{
    global $failures;
    if (!str_contains($haystack, $needle)) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: missing {$needle}" . PHP_EOL);
    }
}

function assertTrue(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}" . PHP_EOL);
    }
}

function assertNotContainsText(string $needle, string $haystack, string $message): void
{
    global $failures;
    if (str_contains($haystack, $needle)) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: found {$needle}" . PHP_EOL);
    }
}

$html = (new AdminRenderer())->dashboard([
    'data_dir' => '/tmp/mini-s3-data',
    'status' => 'ok',
    'bucket_count' => 0,
    'object_count' => 2,
    'total_bytes' => 3,
], [
    'CREDENTIALS' => ['client-key' => ['secret' => 'client-secret', 'access' => 'readwrite', 'label' => '', 'created' => 0]],
], 'https://s3.example.test', [
    'state' => 'update_available',
    'message' => 'Update available: v1.0.2',
    'currentVersion' => 'v1.0.1',
    'latestVersion' => 'v1.0.2',
    'assetUrl' => 'https://example.test/mini-s3-v1.0.2.zip',
], 'Upgrade ready', 'csrf-token', ['public-read' => 'Public read is on <danger>']);

assertContainsText('Connect an app', $html, 'dashboard shows connect panel');
assertContainsText('https://s3.example.test', $html, 'dashboard shows endpoint');
assertContainsText('href="/_/keys"', $html, 'dashboard links to access keys');
assertContainsText('Getting started', $html, 'empty server shows getting started steps');
assertContainsText('s3 mb s3://my-bucket', $html, 'getting started shows create bucket command');
assertNotContainsText('client-secret', $html, 'dashboard never embeds secrets');
assertNotContainsText('ADMIN_PASSWORD_HASH', $html, 'admin config is not dumped');
assertContainsText('Public read is on &lt;danger&gt;', $html, 'warnings are escaped and rendered');
assertContainsText('miniS3:dismiss:public-read', $html, 'warnings can be dismissed');
assertContainsText('<strong>Access keys</strong><span>1</span>', $html, 'dashboard counts access keys');
assertContainsText('data-copy="https://s3.example.test"', $html, 'endpoint has a copy button');
assertContainsText('1 key', $html, 'getting started marks the key step done');
assertContainsText('Updates', $html, 'dashboard shows updates panel');
assertContainsText('Current version:</strong> v1.0.1', $html, 'current version is rendered');
assertContainsText('Latest version:</strong> v1.0.2', $html, 'latest version is rendered');
assertContainsText('Upgrade to v1.0.2', $html, 'upgrade button is rendered');
assertContainsText('action="/_/upgrade"', $html, 'upgrade form posts to upgrade route');
assertNotContainsText('name="asset_url"', $html, 'upgrade form does not trust client asset url');
assertNotContainsText('name="latest_version"', $html, 'upgrade form does not trust client version');
assertContainsText('Check update', $html, 'check update button is rendered for release installs');
assertContainsText('action="/_/check-update"', $html, 'check update form posts to check route');
assertContainsText('Upgrade ready', $html, 'flash message is rendered');
assertContainsText('<form method="post" action="/_/logout" class="plain nav-logout"><input type="hidden" name="csrf_token" value="csrf-token">', $html, 'logout is a CSRF-protected POST');
assertNotContainsText('href="/_/logout"', $html, 'logout is not a GET link');
assertNotContainsText('unpkg.com', $html, 'no third-party CDN scripts');
assertContainsText('<script defer src="/_/assets/admin-' . MiniS3\Admin\AdminAssets::SCRIPT_HASH . '.js"></script>', $html, 'self-hosted admin script is loaded');
$adminJsSource = (string) file_get_contents(__DIR__ . '/../../go/internal/admin/assets/admin.js');
assertTrue(MiniS3\Admin\AdminAssets::script() === $adminJsSource, 'AdminAssets matches admin.js (run php scripts/build-admin-assets.php)');
assertTrue(MiniS3\Admin\AdminAssets::SCRIPT_HASH === substr(hash('sha256', $adminJsSource), 0, 12), 'script hash matches admin.js, as the Go edition computes it');
assertContainsText('<a class="nav-link" href="/_" aria-current="page">', $html, 'current nav item is marked');
assertNotContainsText('href="/_/files" aria-current', $html, 'other nav items are not marked');
assertContainsText('<div class="error" role="alert">boom</div>', (new AdminRenderer())->dashboard(['data_dir' => '', 'status' => 'ok', 'bucket_count' => 1, 'object_count' => 0, 'total_bytes' => 0], [], '', [], 'Error: boom', 'csrf-token'), 'error flash renders as error');

$loginHtml = (new AdminRenderer())->login('', 'csrf-token');
assertContainsText('name="username"', $loginHtml, 'login form includes username field');
assertContainsText('name="password"', $loginHtml, 'login form includes password field');
assertContainsText('aria-label="Show password"', $loginHtml, 'login password can be revealed');
assertContainsText('ADMIN_PASSWORD_HASH', $loginHtml, 'login explains password reset');
assertNotContainsText('class="sidebar"', $loginHtml, 'login has no admin navigation');

$settingsHtml = (new AdminRenderer())->settings([
    'data_dir' => '/tmp/mini-s3-data',
    'max_request_size' => '104857600',
    'public_read_all_buckets' => true,
    'allow_host_candidate_fallbacks' => false,
    'clock_skew_seconds' => '900',
    'max_presign_expires' => '604800',
    'auth_debug_log' => '',
], [], 'csrf-token', 'Settings saved.', ['DATA_DIR' => 'MINI_S3_DATA_DIR']);
assertContainsText('action="/_/settings"', $settingsHtml, 'settings form posts to settings route');
assertNotContainsText('name="admin_username"', $settingsHtml, 'settings has no admin username field');
assertNotContainsText('name="access_key"', $settingsHtml, 'settings has no access key field');
assertNotContainsText('name="secret_key"', $settingsHtml, 'settings has no secret key field');
assertNotContainsText('name="auth_debug_log"', $settingsHtml, 'auth debug log is not editable from the UI');
assertContainsText('<input id="field-data_dir" name="data_dir" value="/tmp/mini-s3-data" type="text" disabled', $settingsHtml, 'env-locked field is disabled');
assertContainsText('env: MINI_S3_DATA_DIR', $settingsHtml, 'env-locked field shows its variable');
assertContainsText('<input type="hidden" name="public_read_all_buckets" value="0">', $settingsHtml, 'public read has hidden 0 fallback');
assertContainsText('name="public_read_all_buckets" value="1" checked', $settingsHtml, 'public read checkbox reflects value');
assertContainsText('Leave off unless this server only stores public files', $settingsHtml, 'public read has inline help');
assertContainsText('RequestTimeTooSkewed', $settingsHtml, 'clock skew has inline help');
assertContainsText('upload_max_filesize', $settingsHtml, 'upload size help shows PHP limits');
assertContainsText('Settings saved.', $settingsHtml, 'settings flash is rendered');

$installerHtml = (new AdminRenderer())->installer(['admin_username' => 'admin', 'data_dir' => '/srv/data'], [], 'csrf-token');
assertContainsText('name="admin_password"', $installerHtml, 'installer asks for admin password');
assertContainsText('minlength="8"', $installerHtml, 'installer enforces password length in the browser');
assertNotContainsText('name="access_key"', $installerHtml, 'installer does not ask for an access key');
assertNotContainsText('name="secret_key"', $installerHtml, 'installer does not ask for a secret key');
assertNotContainsText('public_read_all_buckets', $installerHtml, 'installer does not offer public read');
assertContainsText('value="/srv/data"', $installerHtml, 'installer prefills data directory');
assertContainsText('take over the server', $installerHtml, 'installer warns to finish setup');
assertNotContainsText('<nav>', $installerHtml, 'installer has no navigation');

$keysHtml = (new AdminRenderer())->keys([
    'AKREADONLY' => ['secret' => 'read-secret-value', 'access' => 'read', 'label' => 'CDN <x>', 'created' => 1700000000],
    'AKREADWRITE' => ['secret' => 'rw-secret-value', 'access' => 'readwrite', 'label' => '', 'created' => 0],
], 'https://s3.example.test', 'csrf-token', 'Access key created.', 'AKREADONLY');
assertContainsText('Your new access key', $keysHtml, 'new key panel is shown');
assertContainsText('<code>read-secret-value</code>', $keysHtml, 'new key secret is shown once created');
assertNotContainsText('rw-secret-value', $keysHtml, 'other secrets are not embedded');
assertContainsText('Read only</span>', $keysHtml, 'read-only badge is rendered');
assertContainsText('Read &amp; write</span>', $keysHtml, 'read/write badge is rendered');
assertContainsText('CDN &lt;x&gt;', $keysHtml, 'labels are escaped');
assertContainsText('name="action" value="create"', $keysHtml, 'create key form is rendered');
assertContainsText('name="action" value="delete"', $keysHtml, 'delete key form is rendered');
assertContainsText('name="confirm"', $keysHtml, 'delete requires typed confirmation');
assertContainsText('"awscli"', $keysHtml, 'AWS CLI snippet is available');
assertContainsText('force_path_style = true', $keysHtml, 'rclone snippet is available');
assertContainsText('forcePathStyle: true', $keysHtml, 'JavaScript snippet is available');
assertContainsText('AWS_USE_PATH_STYLE_ENDPOINT=true', $keysHtml, 'Laravel snippet is available');
assertContainsText('<div data-keys>', $keysHtml, 'keys page is wired to the admin script');
assertContainsText('<option value="AKREADONLY" selected>', $keysHtml, 'the new key is selected in the snippet picker');
assertContainsText('data-tab="laravel">Laravel</button>', $keysHtml, 'snippet tabs are rendered on the server');

$envKeysHtml = (new AdminRenderer())->keys(['envkey' => ['secret' => 's', 'access' => 'readwrite', 'label' => '', 'created' => 0]], 'https://s3.example.test', 'csrf-token', '', '', true);
assertContainsText('MINI_S3_CREDENTIALS_JSON', $envKeysHtml, 'env-managed keys explain where they come from');
assertNotContainsText('value="create"', $envKeysHtml, 'env-managed keys cannot be created');
assertNotContainsText('value="delete"', $envKeysHtml, 'env-managed keys cannot be deleted');

$securityHtml = (new AdminRenderer())->security([
    'admin_username' => 'owner',
], [], 'csrf-token', 'Saved');
assertContainsText('action="/_/security"', $securityHtml, 'security form posts to security route');
assertContainsText('name="admin_username"', $securityHtml, 'security form includes admin username field');
assertContainsText('value="owner"', $securityHtml, 'security form renders current admin username');
assertContainsText('name="current_password"', $securityHtml, 'security form requires current password');
assertContainsText('name="new_password"', $securityHtml, 'security form includes new password field');
assertContainsText('Saved', $securityHtml, 'security page renders flash message');

$filesHtml = (new AdminRenderer())->files([
    ['name' => 'photos', 'object_count' => 2, 'total_bytes' => 1024],
], [
    'folders' => [
        ['name' => 'nested', 'path' => 'nested', 'object_count' => 1, 'total_bytes' => 2048, 'modified' => 1700000000],
    ],
    'files' => [
        ['name' => 'cover.png', 'path' => 'cover.png', 'size' => 12, 'modified' => 1700000000, 'mime' => 'image/png', 'is_image' => true],
        ['name' => 'logo.svg', 'path' => 'logo.svg', 'size' => 12, 'modified' => 1700000000, 'mime' => 'image/svg+xml', 'is_image' => true],
    ],
], 'photos', '', 'csrf-token', 'Ready');
assertContainsText('href="/_/files"', $filesHtml, 'files page renders breadcrumb root');
assertContainsText('<div data-explorer data-csrf="csrf-token" data-bucket="photos" data-prefix="">', $filesHtml, 'files page is wired to the admin script');
assertContainsText('data-action="upload"', $filesHtml, 'files page renders upload action');
assertContainsText('cover.png', $filesHtml, 'files page renders file name');
assertContainsText('download=1', $filesHtml, 'files page renders download link');
assertContainsText('path=cover.png&download=0', $filesHtml, 'raster image preview thumbnail is rendered');
assertNotContainsText('path=logo.svg&download=0', $filesHtml, 'svg files get no inline preview');
assertContainsText('data-stat-src="/_/folder-stats?bucket=photos&amp;prefix=" data-stat-key="nested" data-stat-field="summary"', $filesHtml, 'folder row size is a placeholder');
assertContainsText('data-stat-src="/_/stats" data-stat-key="bucket:photos" data-stat-field="size"', $filesHtml, 'bucket rail size is a placeholder');

$statsFields = (new AdminRenderer())->statsFields(['object_count' => 3, 'total_bytes' => 2048, 'buckets' => [
    ['name' => 'a', 'object_count' => 1, 'total_bytes' => 512],
    ['name' => 'b', 'object_count' => 2, 'total_bytes' => 1536],
]]);
assertTrue(($statsFields['all']['summary'] ?? null) === '2 buckets · 3 objects · 2 KB stored', 'stats summary is formatted on the server');
assertTrue(($statsFields['bucket:a'] ?? null) === ['objects' => '1', 'size' => '512 B', 'share' => '25%'], 'bucket stats are formatted on the server');
assertTrue(
    (new AdminRenderer())->folderStatsFields(['docs' => ['object_count' => 1, 'total_bytes' => 2048]]) === ['docs' => ['summary' => '1 item · 2 KB']],
    'folder stats are formatted on the server'
);
assertContainsText('data-filter', $filesHtml, 'files page renders the filter box');
assertContainsText('<dialog class="dialog-panel"', $filesHtml, 'files page renders a native dialog');
assertContainsText('data-action="rename-object" data-path="cover.png" data-name="cover.png"', $filesHtml, 'files page renders rename action');
assertContainsText('data-status>1 folders, 2 files</div>', $filesHtml, 'files page renders the listing summary');
assertContainsText('New bucket', $filesHtml, 'files page renders create bucket action');
assertContainsText('data-details="', $filesHtml, 'clicking a file opens its details');
assertNotContainsText('data-href="/_/files?bucket=photos&amp;path=cover.png&amp;download=1"', $filesHtml, 'clicking a file row does not download it');
assertContainsText('class="drawer"', $filesHtml, 'files page renders the details drawer');
assertContainsText('class="bucket-rail"', $filesHtml, 'bucket rail is shown inside a bucket');
$dashboardWithBuckets = (new AdminRenderer())->dashboard(['data_dir' => '/d', 'status' => 'ok', 'bucket_count' => 1, 'object_count' => 2, 'total_bytes' => 10, 'buckets' => [['name' => 'pics <x>']]], ['CREDENTIALS' => ['k' => ['secret' => 's', 'access' => 'read', 'label' => '', 'created' => 0]]], 'https://s3.example.test', [], '', 'csrf-token');
assertContainsText('pics &lt;x&gt;', $dashboardWithBuckets, 'bucket names are escaped on the dashboard');
assertContainsText('data-stat-src="/_/stats" data-stat-key="bucket:pics &lt;x&gt;" data-stat-field="size"', $dashboardWithBuckets, 'bucket sizes are placeholders filled in after load');
assertContainsText('data-stat-key="all" data-stat-field="summary"', $dashboardWithBuckets, 'dashboard summary is a placeholder');
assertContainsText('data-stat-field="share" data-stat-style="width"', $dashboardWithBuckets, 'share bar width is a placeholder');
assertNotContainsText('Getting started', $dashboardWithBuckets, 'checklist hides once a key and a bucket exist');
assertContainsText('No buckets yet.', (new AdminRenderer())->files([], ['folders' => [], 'files' => []], '', '', 'csrf-token', ''), 'empty bucket list explains what to do');

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
assertNotContainsText('Check update', $unavailableHtml, 'source install has no check update button');

// Pages get all behaviour from the admin script, so the CSP can forbid inline
// script: no inline <script> code, inline event handlers or Alpine directives.
$warningHtml = (new AdminRenderer())->dashboard(['data_dir' => '', 'status' => 'ok', 'bucket_count' => 0, 'object_count' => 0, 'total_bytes' => 0], [], '', [], '', 'csrf-token', ['http' => 'Served over HTTP.']);
assertContainsText('data-dismiss-key="miniS3:dismiss:http"', $warningHtml, 'warnings can be dismissed');
foreach (['dashboard' => $html, 'warnings' => $warningHtml, 'login' => $loginHtml, 'installer' => $installerHtml, 'settings' => $settingsHtml, 'security' => $securityHtml, 'keys' => $keysHtml, 'files' => $filesHtml] as $page => $pageHtml) {
    $found = preg_match('/<script(?![^>]*\b(?:src=|type="application\/json"))[^>]*>|\son[a-z]+=|\sx-[a-z]+|@click/', $pageHtml, $match);
    assertTrue($found === 0, $page . ' page has no inline behaviour' . ($found ? ': ' . $match[0] : ''));
}

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminRenderer tests passed" . PHP_EOL;
