<?php

declare(strict_types=1);

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

assertContainsText('Connection config', $html, 'dashboard shows connection panel');
assertContainsText('MINI_S3_ENDPOINT=https://s3.example.test', $html, 'generic endpoint is rendered');
assertContainsText('MINI_S3_REGION=us-east-1', $html, 'generic region is rendered');
assertContainsText('MINI_S3_BUCKET=your-bucket', $html, 'generic bucket placeholder is rendered');
assertContainsText('AWS_ENDPOINT=https://s3.example.test', $html, 'laravel endpoint is rendered');
assertContainsText('AWS_USE_PATH_STYLE_ENDPOINT=true', $html, 'laravel path style flag is rendered');
assertContainsText('Show sensitive', $html, 'sensitive toggle is rendered');
assertContainsText('copySnippet', $html, 'copy button script is rendered');
assertContainsText('client-key', $html, 'unmasked access key is available after toggle');
assertContainsText('client-secret', $html, 'unmasked secret key is available after toggle');
assertContainsText('clie...-key', $html, 'masked access key is visible by default');
assertContainsText('clie...cret', $html, 'masked secret key is visible by default');
assertNotContainsText('ADMIN_PASSWORD_HASH', $html, 'admin config is not dumped');
assertContainsText('Updates', $html, 'dashboard shows updates panel');
assertContainsText('Current version:</strong> v1.0.1', $html, 'current version is rendered');
assertContainsText('Latest version:</strong> v1.0.2', $html, 'latest version is rendered');
assertContainsText('Upgrade to v1.0.2', $html, 'upgrade button is rendered');
assertContainsText('action="/_/upgrade"', $html, 'upgrade form posts to upgrade route');
assertContainsText('Check update', $html, 'check update button is rendered for release installs');
assertContainsText('action="/_/check-update"', $html, 'check update form posts to check route');
assertContainsText('Upgrade ready', $html, 'flash message is rendered');

$loginHtml = (new AdminRenderer())->login('', 'csrf-token');
assertContainsText('name="username"', $loginHtml, 'login form includes username field');
assertContainsText('name="password"', $loginHtml, 'login form includes password field');

$configHtml = (new AdminRenderer())->config([
    'admin_username' => 'owner',
    'data_dir' => '/tmp/mini-s3-data',
    'access_key' => 'client-key',
    'secret_key' => 'client-secret',
], [], 'csrf-token');
assertContainsText('name="admin_username"', $configHtml, 'config form includes admin username field');
assertContainsText('value="owner"', $configHtml, 'config form renders current admin username');

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

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminRenderer tests passed" . PHP_EOL;
