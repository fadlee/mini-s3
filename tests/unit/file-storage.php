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

$base = sys_get_temp_dir() . '/mini-s3-storage-' . bin2hex(random_bytes(4));
mkdir($base . '/bucket', 0777, true);
file_put_contents($base . '/bucket/object.bin', 'hello');

$storage = new FileStorage($base);
$metadata = $storage->objectMetadata('bucket', 'object.bin');

assertSameValue(5, $metadata['size'] ?? null, 'object size is read');
assertSameValue(true, is_string($metadata['mimeType'] ?? null) && $metadata['mimeType'] !== '', 'mime type has non-empty fallback');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] FileStorage tests passed" . PHP_EOL;
}
