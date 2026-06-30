<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminFileExplorer.php';

use MiniS3\Admin\AdminFileExplorer;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

function assertTrueValue(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}" . PHP_EOL);
    }
}

$base = sys_get_temp_dir() . '/mini-s3-explorer-' . bin2hex(random_bytes(4));
mkdir($base . '/alpha/nested', 0777, true);
file_put_contents($base . '/alpha/readme.txt', 'hello');
file_put_contents($base . '/alpha/nested/image.png', 'png');

$explorer = new AdminFileExplorer($base);
$buckets = $explorer->listBuckets();
assertSameValue('alpha', $buckets[0]['name'] ?? null, 'lists bucket name');

$listing = $explorer->listObjects('alpha', '');
assertSameValue('nested', $listing['folders'][0]['name'] ?? null, 'lists folder in bucket root');
assertSameValue('readme.txt', $listing['files'][0]['name'] ?? null, 'lists file in bucket root');

$explorer->createFolder('alpha', 'docs');
assertTrueValue(is_dir($base . '/alpha/docs'), 'creates folder');

$renamed = $explorer->rename('alpha', 'readme.txt', 'guide.txt');
assertSameValue('guide.txt', $renamed['path'] ?? null, 'rename returns new path');
assertTrueValue(is_file($base . '/alpha/guide.txt'), 'renames file');

$explorer->renameBucket('alpha', 'beta');
assertTrueValue(is_dir($base . '/beta'), 'renames bucket');

$info = $explorer->objectInfo('beta', 'guide.txt');
assertSameValue('guide.txt', $info['name'] ?? null, 'reads object info');

$explorer->deleteObject('beta', 'docs');
assertTrueValue(!is_dir($base . '/beta/docs'), 'deletes folder');

$explorer->deleteObject('beta', 'guide.txt');
assertTrueValue(!is_file($base . '/beta/guide.txt'), 'deletes file');

$explorer->deleteBucket('beta');
assertTrueValue(!is_dir($base . '/beta'), 'deletes bucket');

try {
    $explorer->createFolder('missing', 'docs');
    assertTrueValue(false, 'missing bucket should fail');
} catch (RuntimeException $e) {
    assertSameValue('Bucket not found: missing', $e->getMessage(), 'missing bucket is rejected');
}

try {
    $explorer->objectInfo('alpha', '../escape.txt');
    assertTrueValue(false, 'path traversal should fail');
} catch (RuntimeException $e) {
    assertSameValue('Invalid name', $e->getMessage(), 'path traversal is rejected');
}

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminFileExplorer tests passed" . PHP_EOL;
