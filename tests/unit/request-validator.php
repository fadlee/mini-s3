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
check(!$validator->isValidObjectKey('a/.htaccess'), 'nested htaccess key rejected');
check(!$validator->isValidObjectKey('.HTACCESS'), 'uppercase htaccess key rejected');
check(!$validator->isValidObjectKey('x/.user.ini'), 'user.ini key rejected');
check(!$validator->isValidObjectKey('x/.HtPasswd'), 'htpasswd key rejected case-insensitively');
check($validator->isValidObjectKey('a/.hidden'), 'other dotfile key accepted');
check($validator->isValidObjectKey('normal.txt'), 'normal file key accepted');

check($validator->isValidUploadId(bin2hex(random_bytes(16))), 'generated upload id accepted');
check(!$validator->isValidUploadId('../x'), 'traversal upload id rejected');
check(!$validator->isValidUploadId(''), 'empty upload id rejected');
check(!$validator->isValidUploadId(strtoupper(str_repeat('ab', 16))), 'uppercase upload id rejected');
check(!$validator->isValidUploadId(str_repeat('a', 31)), '31 char upload id rejected');
check(!$validator->isValidUploadId(str_repeat('a', 33)), '33 char upload id rejected');
check(!$validator->isValidUploadId('abc/def'), 'slash upload id rejected');

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

$mtime = 1761170000;
$etag = $validator->objectEtag($mtime, 31143);
check($etag === '"' . dechex($mtime) . '-79a7"', 'etag derived from mtime and size');
check(preg_match('/^"[a-f0-9]{32}"$/', $etag) !== 1, 'etag does not look like an md5');
check($validator->isNotModified($etag, null, $etag, $mtime), 'matching If-None-Match is not modified');
check($validator->isNotModified('"other", W/' . $etag, null, $etag, $mtime), 'weak etag in list matches');
check($validator->isNotModified('*', null, $etag, $mtime), 'wildcard If-None-Match matches');
check(!$validator->isNotModified('"other"', gmdate('D, d M Y H:i:s', $mtime) . ' GMT', $etag, $mtime), 'If-None-Match takes precedence over If-Modified-Since');
check($validator->isNotModified(null, gmdate('D, d M Y H:i:s', $mtime) . ' GMT', $etag, $mtime), 'unchanged since If-Modified-Since');
check(!$validator->isNotModified(null, gmdate('D, d M Y H:i:s', $mtime - 1) . ' GMT', $etag, $mtime), 'modified after If-Modified-Since');
check(!$validator->isNotModified(null, 'garbage', $etag, $mtime), 'invalid If-Modified-Since ignored');
check(!$validator->isNotModified(null, null, $etag, $mtime), 'no conditional headers means modified');
check($validator->isRangeApplicable(null, $etag, $mtime), 'range applies without If-Range');
check($validator->isRangeApplicable($etag, $etag, $mtime), 'range applies when If-Range etag matches');
check(!$validator->isRangeApplicable('"other"', $etag, $mtime), 'range ignored when If-Range etag differs');
check(!$validator->isRangeApplicable('W/' . $etag, $etag, $mtime), 'weak If-Range never matches');
check($validator->isRangeApplicable(gmdate('D, d M Y H:i:s', $mtime) . ' GMT', $etag, $mtime), 'range applies when If-Range date matches');
check(!$validator->isRangeApplicable(gmdate('D, d M Y H:i:s', $mtime + 5) . ' GMT', $etag, $mtime), 'range ignored when If-Range date differs');

check($validator->isOversizedRequest('104857601', 104857600), 'oversized request detected');
check(!$validator->isOversizedRequest('104857600', 104857600), 'max-sized request accepted');
check(!$validator->isOversizedRequest('abc', 104857600), 'invalid content length ignored like current behavior');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] RequestValidator tests passed" . PHP_EOL;
