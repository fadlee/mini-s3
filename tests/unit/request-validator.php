<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/S3/RequestValidator.php';

use MiniS3\S3\RequestValidator;

$validator = new RequestValidator();
$failures = 0;

function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}" . PHP_EOL);
    }
}

check($validator->isValidBucketName('valid-bucket'), 'valid bucket accepted');
check(!$validator->isValidBucketName('ab'), 'short bucket rejected');
check(!$validator->isValidBucketName('Invalid'), 'uppercase bucket rejected');
check(!$validator->isValidBucketName('192.168.0.1'), 'ip-looking bucket rejected');

check($validator->isValidObjectKey('path/file.txt'), 'normal object key accepted');
check(!$validator->isValidObjectKey("bad\0key"), 'NUL object key rejected');
check(!$validator->isValidObjectKey('../secret'), 'parent segment rejected');
check(!$validator->isValidObjectKey('./secret'), 'dot segment rejected');

check($validator->isPositiveInteger('1'), 'positive integer accepted');
check(!$validator->isPositiveInteger('0'), 'zero rejected');
check(!$validator->isPositiveInteger('abc'), 'non-digit rejected');

[$valid, $start, $end] = $validator->parseRange('bytes=0-3', 10);
check($valid && $start === 0 && $end === 3, 'normal range parsed');

[$valid, $start, $end] = $validator->parseRange('bytes=-4', 10);
check($valid && $start === 6 && $end === 9, 'suffix range parsed');

[$valid] = $validator->parseRange('bytes=999-1000', 10);
check(!$valid, 'out of range rejected');

[$valid] = $validator->parseRange('items=0-1', 10);
check(!$valid, 'wrong unit rejected');

check($validator->isOversizedRequest('104857601', 104857600), 'oversized request detected');
check(!$validator->isOversizedRequest('104857600', 104857600), 'max-sized request accepted');
check(!$validator->isOversizedRequest('abc', 104857600), 'invalid content length ignored like current behavior');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] RequestValidator tests passed" . PHP_EOL;
