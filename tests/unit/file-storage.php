<?php

declare(strict_types=1);

namespace MiniS3\Storage {
    function mime_content_type(string $filename): bool|string
    {
        throw new \Error('mime_content_type unavailable');
    }
}

namespace {
require_once __DIR__ . '/../../src/Storage/FileStorage.php';

use MiniS3\Storage\FileStorage;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

function removeTreeForTest(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $item) {
        if ($item !== '.' && $item !== '..') {
            removeTreeForTest($path . '/' . $item);
        }
    }
    rmdir($path);
}

$base = sys_get_temp_dir() . '/mini-s3-storage-' . bin2hex(random_bytes(4));
mkdir($base . '/bucket', 0777, true);
file_put_contents($base . '/bucket/object.bin', 'hello');

$storage = new FileStorage($base);
$metadata = $storage->objectMetadata('bucket', 'object.bin');

assertSameValue(5, $metadata['size'] ?? null, 'object size is read');
assertSameValue(true, is_string($metadata['mimeType'] ?? null) && $metadata['mimeType'] !== '', 'mime type has non-empty fallback');

$attackRoot = sys_get_temp_dir() . '/mini-s3-attack-' . bin2hex(random_bytes(4));
mkdir($attackRoot . '/data', 0777, true);
mkdir($attackRoot . '/victim', 0777, true);
file_put_contents($attackRoot . '/victim/secret.txt', 'keep');
$attackStorage = new FileStorage($attackRoot . '/data');
$validUploadId = bin2hex(random_bytes(16));
$attackStorage->createMultipartUpload('bucket', 'k', $validUploadId);

$threw = false;
try {
    $attackStorage->abortMultipartUpload('bucket', 'k', '../../../../victim');
} catch (RuntimeException $e) {
    $threw = $e->getMessage() === 'Invalid upload ID';
}
assertSameValue(true, $threw, 'traversal upload ID is rejected by abort');
assertSameValue(true, is_file($attackRoot . '/victim/secret.txt'), 'victim directory survives traversal abort');

$threw = false;
try {
    $storage->multipartDir('bucket', 'k', '../../victim');
} catch (RuntimeException) {
    $threw = true;
}
assertSameValue(true, $threw, 'multipartDir rejects traversal upload ID');

$htRoot = sys_get_temp_dir() . '/mini-s3-htaccess-' . bin2hex(random_bytes(4));
$newDataDir = $htRoot . '/new-data';
(new FileStorage($newDataDir))->ensureDataDirExists();
assertSameValue(true, str_contains((string) @file_get_contents($newDataDir . '/.htaccess'), 'Require all denied'), 'htaccess written to new data dir');

$existingDataDir = $htRoot . '/existing-data';
mkdir($existingDataDir, 0777, true);
(new FileStorage($existingDataDir))->ensureDataDirExists();
assertSameValue(true, str_contains((string) @file_get_contents($existingDataDir . '/.htaccess'), 'Require all denied'), 'htaccess written to existing data dir');

$customDataDir = $htRoot . '/custom-data';
mkdir($customDataDir, 0777, true);
file_put_contents($customDataDir . '/.htaccess', 'custom');
(new FileStorage($customDataDir))->ensureDataDirExists();
assertSameValue('custom', file_get_contents($customDataDir . '/.htaccess'), 'existing htaccess is not overwritten');

removeTreeForTest($base);
removeTreeForTest($attackRoot);
removeTreeForTest($htRoot);

if ($failures > 0) {
    exit(1);
}

echo "[PASS] FileStorage tests passed" . PHP_EOL;
}
