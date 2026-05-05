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
