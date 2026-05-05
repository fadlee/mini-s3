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
