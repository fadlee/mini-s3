<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/../..');
if ($root === false) {
    fail('Unable to locate project root');
}

$phpBin = getenv('PHP_BIN');
$phpBin = is_string($phpBin) && $phpBin !== '' ? $phpBin : PHP_BINARY;
$sigv4Helper = $root . '/tests/integration/sigv4.php';
$requestHelper = $root . '/tests/integration/request.php';
$signHost = 'mini-s3.test';
$signBaseUrl = 'http://' . $signHost;
$accessKey = getenv('AWS_ACCESS_KEY_ID') ?: 'minioadmin';
$secretKey = getenv('AWS_SECRET_ACCESS_KEY') ?: 'minioadmin';
putenv('MINI_S3_CREDENTIALS_JSON=' . json_encode([$accessKey => $secretKey], JSON_UNESCAPED_SLASHES));
putenv('MINI_S3_PUBLIC_READ_ALL_BUCKETS=true');

$tmpDir = createTempDirectory($root . '/data/.test-tmp', 'mini-s3-int-');
$testBucket = 'itest-' . time() . '-' . random_int(1000, 999999);
$testKey = 'hello.txt';
$configPath = $root . '/config/config.php';
$configBackupPath = null;
$createdConfig = false;

register_shutdown_function(static function () use (&$configBackupPath, &$createdConfig, $configPath, $tmpDir, $root, $testBucket): void {
    if (is_string($configBackupPath) && is_file($configBackupPath)) {
        ensureDirectory(dirname($configPath));
        @rename($configBackupPath, $configPath);
    }
    if ($createdConfig) {
        @unlink($configPath);
    }
    removePath($tmpDir);
    removePath($root . '/data/' . $testBucket);
    removePath($root . '/data/.multipart/' . $testBucket);
    @rmdir($root . '/data/.multipart');
});

echo "[INFO] Starting mini-s3 integration tests (CLI harness)\n";

if (is_file($configPath)) {
    $configBackupPath = $tmpDir . '/config.php.backup';
    rename($configPath, $configBackupPath);
}
runRequest($phpBin, $requestHelper, 'GET', '/_', null, $tmpDir . '/admin-installer.body', $tmpDir . '/admin-installer.meta', ['Host: ' . $signHost]);
runRequest($phpBin, $requestHelper, 'POST', '/_/upgrade', null, $tmpDir . '/admin-upgrade-installer.body', $tmpDir . '/admin-upgrade-installer.meta', ['Host: ' . $signHost]);
if (is_string($configBackupPath) && is_file($configBackupPath)) {
    rename($configBackupPath, $configPath);
    $configBackupPath = null;
} else {
    ensureDirectory($root . '/config');
    $configPhp = "<?php\n\nreturn [\n    'DATA_DIR' => '" . addslashes($root . '/data') . "',\n    'CREDENTIALS' => ['" . addslashes($accessKey) . "' => '" . addslashes($secretKey) . "'],\n    'ADMIN_USERNAME' => 'admin',\n    'ADMIN_PASSWORD_HASH' => 'test-hash',\n    'PUBLIC_READ_ALL_BUCKETS' => true,\n];\n";
    file_put_contents($configPath, $configPhp);
    $createdConfig = true;
}
assertEq('200', metaStatus($tmpDir . '/admin-installer.meta', $phpBin), 'Admin installer route should succeed');
assertContains('Install Mini S3', $tmpDir . '/admin-installer.body', 'Admin installer should render setup page');
assertNotContains('<?xml', $tmpDir . '/admin-installer.body', 'Admin installer should not render S3 XML');
assertEq('400', metaStatus($tmpDir . '/admin-upgrade-installer.meta', $phpBin), 'Installer-mode upgrade POST should be rejected as invalid installer submission');

runRequest($phpBin, $requestHelper, 'POST', '/_/upgrade', null, $tmpDir . '/admin-upgrade-unauth.body', $tmpDir . '/admin-upgrade-unauth.meta', ['Host: ' . $signHost]);
assertEq('200', metaStatus($tmpDir . '/admin-upgrade-unauth.meta', $phpBin), 'Unauthenticated upgrade route should render login page');
assertContains('Mini S3 Admin Login', $tmpDir . '/admin-upgrade-unauth.body', 'Unauthenticated upgrade route should be protected');

runRequest($phpBin, $requestHelper, 'POST', '/_/check-update', null, $tmpDir . '/admin-check-update-unauth.body', $tmpDir . '/admin-check-update-unauth.meta', ['Host: ' . $signHost]);
assertEq('200', metaStatus($tmpDir . '/admin-check-update-unauth.meta', $phpBin), 'Unauthenticated check-update route should render login page');
assertContains('Mini S3 Admin Login', $tmpDir . '/admin-check-update-unauth.body', 'Unauthenticated check-update route should be protected');

runRequest($phpBin, $requestHelper, 'OPTIONS', '/' . $testBucket . '/' . $testKey, null, $tmpDir . '/cors-preflight.body', $tmpDir . '/cors-preflight.meta', [
    'Host: ' . $signHost,
    'Origin: http://localhost:4321',
    'Access-Control-Request-Method: PUT',
    'Access-Control-Request-Headers: content-length,x-amz-checksum-crc32,x-amz-sdk-checksum-algorithm',
]);
assertEq('204', metaStatus($tmpDir . '/cors-preflight.meta', $phpBin), 'CORS preflight should succeed');

file_put_contents($tmpDir . '/hello.txt', "hello integration test\n");
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'PUT', '/' . $testBucket . '/' . $testKey, $tmpDir . '/hello.txt', $tmpDir . '/put.body', $tmpDir . '/put.meta');
assertEq('200', metaStatus($tmpDir . '/put.meta', $phpBin), 'PUT should succeed');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'GET', '/' . $testBucket . '/', null, $tmpDir . '/list.body', $tmpDir . '/list.meta');
assertEq('200', metaStatus($tmpDir . '/list.meta', $phpBin), 'List should succeed');
assertContains('<Key>' . $testKey . '</Key>', $tmpDir . '/list.body', 'List should include uploaded object');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'GET', '/' . $testBucket . '/' . $testKey, null, $tmpDir . '/get.body', $tmpDir . '/get.meta');
assertEq('200', metaStatus($tmpDir . '/get.meta', $phpBin), 'GET should succeed');
assertSameFile($tmpDir . '/hello.txt', $tmpDir . '/get.body', 'Downloaded body differs from uploaded body');

runRequest($phpBin, $requestHelper, 'GET', '/' . $testBucket . '/' . $testKey, null, $tmpDir . '/public-get.body', $tmpDir . '/public-get.meta', ['Host: ' . $signHost]);
assertEq('200', metaStatus($tmpDir . '/public-get.meta', $phpBin), 'Public GET should succeed without authentication');
assertSameFile($tmpDir . '/hello.txt', $tmpDir . '/public-get.body', 'Public GET body differs from uploaded body');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'DELETE', '/' . $testBucket . '/' . $testKey, null, $tmpDir . '/del.body', $tmpDir . '/del.meta');
assertEq('204', metaStatus($tmpDir . '/del.meta', $phpBin), 'DELETE should succeed');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'PUT', '/' . $testBucket . '/' . $testKey, $tmpDir . '/hello.txt', $tmpDir . '/put2.body', $tmpDir . '/put2.meta');
assertEq('200', metaStatus($tmpDir . '/put2.meta', $phpBin), 'PUT should succeed for subsequent tests');

$payloadHash = hash_file('sha256', $tmpDir . '/hello.txt');
[$amzDate, $authorization] = signHeaders($phpBin, $sigv4Helper, 'PUT', $signBaseUrl . '/' . $testBucket . '/invalid-sig.txt', $accessKey, $secretKey, $payloadHash);
runRequest($phpBin, $requestHelper, 'PUT', '/' . $testBucket . '/invalid-sig.txt', $tmpDir . '/hello.txt', $tmpDir . '/invalidsig.body', $tmpDir . '/invalidsig.meta', [
    'Host: ' . $signHost,
    'x-amz-date: ' . $amzDate,
    'x-amz-content-sha256: ' . $payloadHash,
    'Authorization: ' . $authorization . '0',
]);
assertEq('401', metaStatus($tmpDir . '/invalidsig.meta', $phpBin), 'Invalid signature must be rejected');

$emptyPayloadHash = hash('sha256', '');
[$hostDate, $hostAuthorization] = signHeaders($phpBin, $sigv4Helper, 'DELETE', $signBaseUrl . '/' . $testBucket . '/' . $testKey, $accessKey, $secretKey, $emptyPayloadHash);
runRequest($phpBin, $requestHelper, 'DELETE', '/' . $testBucket . '/' . $testKey, null, $tmpDir . '/host-mismatch.body', $tmpDir . '/host-mismatch.meta', [
    'Host: internal.local',
    'x-forwarded-host: ' . $signHost,
    'x-amz-date: ' . $hostDate,
    'x-amz-content-sha256: ' . $emptyPayloadHash,
    'Authorization: ' . $hostAuthorization,
]);
assertEq('401', metaStatus($tmpDir . '/host-mismatch.meta', $phpBin), 'Write host mismatch should be rejected even with x-forwarded-host');

$presignedValid = trim(runPhpCapture([$phpBin, $sigv4Helper, 'presign', 'GET', $signBaseUrl . '/' . $testBucket . '/' . $testKey, $accessKey, $secretKey, '120', '0']));
[$validUri, $validHost] = uriAndHostFromUrl($presignedValid);
runRequest($phpBin, $requestHelper, 'GET', $validUri, null, $tmpDir . '/presign-valid.body', $tmpDir . '/presign-valid.meta', ['Host: ' . $validHost]);
assertEq('200', metaStatus($tmpDir . '/presign-valid.meta', $phpBin), 'Valid presigned request should succeed');
assertSameFile($tmpDir . '/hello.txt', $tmpDir . '/presign-valid.body', 'Valid presigned response body mismatch');

$presignedExpired = trim(runPhpCapture([$phpBin, $sigv4Helper, 'presign', 'PUT', $signBaseUrl . '/' . $testBucket . '/expired-put.txt', $accessKey, $secretKey, '1', '-3600']));
[$expiredUri, $expiredHost] = uriAndHostFromUrl($presignedExpired);
runRequest($phpBin, $requestHelper, 'PUT', $expiredUri, $tmpDir . '/hello.txt', $tmpDir . '/presign-expired.body', $tmpDir . '/presign-expired.meta', ['Host: ' . $expiredHost]);
assertEq('401', metaStatus($tmpDir . '/presign-expired.meta', $phpBin), 'Expired presigned write request should be rejected');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'POST', '/' . $testBucket . '/?delete', $root . '/tests/integration/fixtures/delete-invalid.xml', $tmpDir . '/delete-invalid.body', $tmpDir . '/delete-invalid.meta');
assertEq('400', metaStatus($tmpDir . '/delete-invalid.meta', $phpBin), 'Invalid XML delete request should fail');
assertContains('MalformedXML', $tmpDir . '/delete-invalid.body', 'MalformedXML code should be returned');

file_put_contents($tmpDir . '/part1.bin', 'part-one-');
file_put_contents($tmpDir . '/part2.bin', 'part-two');
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'POST', '/' . $testBucket . '/multi.bin?uploads', null, $tmpDir . '/mp-init.body', $tmpDir . '/mp-init.meta');
assertEq('200', metaStatus($tmpDir . '/mp-init.meta', $phpBin), 'Multipart init should succeed');
$uploadId = extractXmlValue($tmpDir . '/mp-init.body', 'UploadId');
if ($uploadId === '') {
    fail('UploadId not found from multipart init');
}

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'PUT', '/' . $testBucket . '/multi.bin?partNumber=1&uploadId=' . rawurlencode($uploadId), $tmpDir . '/part1.bin', $tmpDir . '/mp-put1.body', $tmpDir . '/mp-put1.meta');
assertEq('200', metaStatus($tmpDir . '/mp-put1.meta', $phpBin), 'Multipart part 1 upload should succeed');
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'PUT', '/' . $testBucket . '/multi.bin?partNumber=2&uploadId=' . rawurlencode($uploadId), $tmpDir . '/part2.bin', $tmpDir . '/mp-put2.body', $tmpDir . '/mp-put2.meta');
assertEq('200', metaStatus($tmpDir . '/mp-put2.meta', $phpBin), 'Multipart part 2 upload should succeed');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'GET', '/' . $testBucket . '/', null, $tmpDir . '/mp-list.body', $tmpDir . '/mp-list.meta');
assertEq('200', metaStatus($tmpDir . '/mp-list.meta', $phpBin), 'List should succeed while multipart upload is in progress');
assertNotContains('multi.bin-temp/', $tmpDir . '/mp-list.body', 'Multipart temp directory must not appear in list response');
assertNotContains($uploadId, $tmpDir . '/mp-list.body', 'Multipart upload id must not appear in list response');

file_put_contents($tmpDir . '/mp-complete.xml', "<CompleteMultipartUpload>\n  <Part><PartNumber>1</PartNumber><ETag>\"unused\"</ETag></Part>\n  <Part><PartNumber>2</PartNumber><ETag>\"unused\"</ETag></Part>\n</CompleteMultipartUpload>\n");
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'POST', '/' . $testBucket . '/multi.bin?uploadId=' . rawurlencode($uploadId), $tmpDir . '/mp-complete.xml', $tmpDir . '/mp-complete.body', $tmpDir . '/mp-complete.meta');
assertEq('200', metaStatus($tmpDir . '/mp-complete.meta', $phpBin), 'Multipart complete should succeed');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'GET', '/' . $testBucket . '/multi.bin', null, $tmpDir . '/multi-get.body', $tmpDir . '/multi-get.meta');
assertEq('200', metaStatus($tmpDir . '/multi-get.meta', $phpBin), 'Multipart object GET should succeed');
file_put_contents($tmpDir . '/multi-expected.bin', 'part-one-part-two');
assertSameFile($tmpDir . '/multi-expected.bin', $tmpDir . '/multi-get.body', 'Multipart merged output mismatch');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'POST', '/' . $testBucket . '/concurrent.bin?uploads', null, $tmpDir . '/c-init-a.body', $tmpDir . '/c-init-a.meta');
assertEq('200', metaStatus($tmpDir . '/c-init-a.meta', $phpBin), 'Concurrent upload A init should succeed');
$uploadA = extractXmlValue($tmpDir . '/c-init-a.body', 'UploadId');
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'POST', '/' . $testBucket . '/concurrent.bin?uploads', null, $tmpDir . '/c-init-b.body', $tmpDir . '/c-init-b.meta');
assertEq('200', metaStatus($tmpDir . '/c-init-b.meta', $phpBin), 'Concurrent upload B init should succeed');
$uploadB = extractXmlValue($tmpDir . '/c-init-b.body', 'UploadId');
file_put_contents($tmpDir . '/c-a1.bin', 'A1');
file_put_contents($tmpDir . '/c-b1.bin', 'B1');
file_put_contents($tmpDir . '/c-b2.bin', 'B2');
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'PUT', '/' . $testBucket . '/concurrent.bin?partNumber=1&uploadId=' . rawurlencode($uploadA), $tmpDir . '/c-a1.bin', $tmpDir . '/c-a1.body', $tmpDir . '/c-a1.meta');
assertEq('200', metaStatus($tmpDir . '/c-a1.meta', $phpBin), 'Concurrent upload A part1 should succeed');
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'PUT', '/' . $testBucket . '/concurrent.bin?partNumber=1&uploadId=' . rawurlencode($uploadB), $tmpDir . '/c-b1.bin', $tmpDir . '/c-b1.body', $tmpDir . '/c-b1.meta');
assertEq('200', metaStatus($tmpDir . '/c-b1.meta', $phpBin), 'Concurrent upload B part1 should succeed');
file_put_contents($tmpDir . '/c-complete-a.xml', "<CompleteMultipartUpload>\n  <Part><PartNumber>1</PartNumber><ETag>\"unused\"</ETag></Part>\n</CompleteMultipartUpload>\n");
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'POST', '/' . $testBucket . '/concurrent.bin?uploadId=' . rawurlencode($uploadA), $tmpDir . '/c-complete-a.xml', $tmpDir . '/c-complete-a.body', $tmpDir . '/c-complete-a.meta');
assertEq('200', metaStatus($tmpDir . '/c-complete-a.meta', $phpBin), 'Concurrent upload A complete should succeed');
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'PUT', '/' . $testBucket . '/concurrent.bin?partNumber=2&uploadId=' . rawurlencode($uploadB), $tmpDir . '/c-b2.bin', $tmpDir . '/c-b2.body', $tmpDir . '/c-b2.meta');
assertEq('200', metaStatus($tmpDir . '/c-b2.meta', $phpBin), 'Upload B should still exist after upload A complete');

file_put_contents($tmpDir . '/too-large.bin', 'x');
signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'PUT', '/' . $testBucket . '/too-large.bin', $tmpDir . '/too-large.bin', $tmpDir . '/too-large.body', $tmpDir . '/too-large.meta', ['Content-Length: 104857601']);
assertEq('413', metaStatus($tmpDir . '/too-large.meta', $phpBin), 'Oversized request should be rejected');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'GET', '/' . $testBucket . '/multi.bin', null, $tmpDir . '/range-valid.body', $tmpDir . '/range-valid.meta', ['Range: bytes=0-3']);
assertEq('206', metaStatus($tmpDir . '/range-valid.meta', $phpBin), 'Valid range request should return 206');
assertEq('4', (string) filesize($tmpDir . '/range-valid.body'), 'Valid range response body length should be 4');

signedRequest($phpBin, $sigv4Helper, $requestHelper, $accessKey, $secretKey, $signBaseUrl, $signHost, 'GET', '/' . $testBucket . '/multi.bin', null, $tmpDir . '/range-invalid.body', $tmpDir . '/range-invalid.meta', ['Range: bytes=99999-100000']);
assertEq('416', metaStatus($tmpDir . '/range-invalid.meta', $phpBin), 'Invalid range request should return 416');

echo "[PASS] All integration scenarios passed\n";

function signedRequest(string $phpBin, string $sigv4Helper, string $requestHelper, string $accessKey, string $secretKey, string $signBaseUrl, string $signHost, string $method, string $uri, ?string $bodyFile, string $outBodyFile, string $outMetaFile, array $extraHeaders = []): void
{
    $payloadHash = $bodyFile !== null ? hash_file('sha256', $bodyFile) : hash('sha256', '');
    [$amzDate, $authorization] = signHeaders($phpBin, $sigv4Helper, $method, $signBaseUrl . $uri, $accessKey, $secretKey, $payloadHash);
    $headers = [
        'Host: ' . $signHost,
        'x-amz-date: ' . $amzDate,
        'x-amz-content-sha256: ' . $payloadHash,
        'Authorization: ' . $authorization,
    ];
    foreach ($extraHeaders as $header) {
        $headers[] = $header;
    }
    runRequest($phpBin, $requestHelper, $method, $uri, $bodyFile, $outBodyFile, $outMetaFile, $headers);
}

function signHeaders(string $phpBin, string $sigv4Helper, string $method, string $fullUrl, string $accessKey, string $secretKey, string $payloadHash): array
{
    $output = trim(runPhpCapture([$phpBin, $sigv4Helper, 'auth', $method, $fullUrl, $accessKey, $secretKey, $payloadHash]));
    $lines = preg_split('/\r?\n/', $output) ?: [];
    $amzDate = '';
    $authorization = '';
    foreach ($lines as $line) {
        if (str_starts_with($line, 'x-amz-date=')) {
            $amzDate = substr($line, strlen('x-amz-date='));
        }
        if (str_starts_with($line, 'authorization=')) {
            $authorization = substr($line, strlen('authorization='));
        }
    }
    if ($amzDate === '' || $authorization === '') {
        fail('Failed to build signed headers');
    }
    return [$amzDate, $authorization];
}

function runRequest(string $phpBin, string $requestHelper, string $method, string $uri, ?string $bodyFile, string $outBodyFile, string $outMetaFile, array $headers): void
{
    $command = escapeshellarg($phpBin) . ' ' . escapeshellarg($requestHelper) . ' ' . escapeshellarg($method) . ' ' . escapeshellarg($uri) . ' ' . escapeshellarg($outMetaFile);
    foreach ($headers as $header) {
        $command .= ' ' . escapeshellarg($header);
    }
    $descriptors = [
        0 => $bodyFile !== null ? ['file', $bodyFile, 'r'] : ['pipe', 'r'],
        1 => ['file', $outBodyFile, 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        fail('Unable to start request helper');
    }
    if ($bodyFile === null && isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
    }
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        fail('Request helper failed: ' . trim((string) $stderr));
    }
}

function metaStatus(string $metaFile, string $phpBin): string
{
    $contents = file_get_contents($metaFile);
    if (!is_string($contents)) {
        fail('Unable to read meta file: ' . $metaFile);
    }
    $decoded = json_decode($contents, true);
    if (!is_array($decoded)) {
        fail('Unable to decode meta file: ' . $metaFile);
    }
    return (string) ($decoded['status'] ?? '');
}

function uriAndHostFromUrl(string $url): array
{
    $parts = parse_url($url);
    if ($parts === false) {
        fail('Unable to parse generated URL');
    }
    $uri = (string) ($parts['path'] ?? '/');
    if (isset($parts['query'])) {
        $uri .= '?' . $parts['query'];
    }
    $host = (string) ($parts['host'] ?? '');
    if (isset($parts['port'])) {
        $host .= ':' . $parts['port'];
    }
    return [$uri, $host];
}

function extractXmlValue(string $path, string $tag): string
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        return '';
    }
    $pattern = '/<' . preg_quote($tag, '/') . '>([^<]*)<\/' . preg_quote($tag, '/') . '>/';
    return preg_match($pattern, $contents, $matches) === 1 ? (string) $matches[1] : '';
}

function runPhpCapture(array $args): string
{
    $command = '';
    foreach ($args as $index => $arg) {
        $command .= ($index === 0 ? '' : ' ') . escapeshellarg($arg);
    }
    $output = [];
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        fail('Command failed: ' . $command);
    }
    return implode(PHP_EOL, $output);
}

function assertEq(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ' (expected=' . $expected . ' actual=' . $actual . ')');
    }
}

function assertContains(string $needle, string $file, string $message): void
{
    $contents = file_get_contents($file);
    if (!is_string($contents) || !str_contains($contents, $needle)) {
        fail($message);
    }
}

function assertNotContains(string $needle, string $file, string $message): void
{
    $contents = file_get_contents($file);
    if (is_string($contents) && str_contains($contents, $needle)) {
        fail($message);
    }
}

function assertSameFile(string $expectedPath, string $actualPath, string $message): void
{
    $expected = file_get_contents($expectedPath);
    $actual = file_get_contents($actualPath);
    if (!is_string($expected) || !is_string($actual) || $expected !== $actual) {
        fail($message);
    }
}

function fail(string $message): void
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        fail('Unable to create directory: ' . $path);
    }
}

function createTempDirectory(string $parentDir, string $prefix): string
{
    ensureDirectory($parentDir);
    $path = rtrim($parentDir, '/\\') . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(6));
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        fail('Unable to create temporary directory');
    }
    return $path;
}

function removePath(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $item) {
        removePath($item->getPathname());
    }
    @rmdir($path);
}
