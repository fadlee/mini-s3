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
], 'https://s3.example.test');

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

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminRenderer tests passed" . PHP_EOL;
