<?php

declare(strict_types=1);

/**
 * Response-header checks over real HTTP. The CLI harness in run.php cannot
 * see headers (headers_list() is empty under the CLI SAPI), so this test
 * starts PHP's built-in server and talks to it with the http stream wrapper.
 *
 * Covers what strict S3 clients (AWS SDK v2, Litestream) rely on:
 * ETag on PutObject / UploadPart / CompleteMultipartUpload, and CreateBucket.
 */

$failures = 0;
$root = realpath(__DIR__ . '/../..');
$phpBin = getenv('PHP_BIN') ?: PHP_BINARY;
$accessKey = 'http-test-key';
$secretKey = 'http-test-secret';
$tmpDir = $root . '/data/.test-tmp/http-' . bin2hex(random_bytes(4));
$dataDir = $tmpDir . '/data';
mkdir($dataDir, 0777, true);

$port = findFreePort();
$host = '127.0.0.1:' . $port;
$env = [
    'MINI_S3_DATA_DIR' => $dataDir,
    'MINI_S3_CREDENTIALS_JSON' => json_encode([$accessKey => $secretKey]),
    'MINI_S3_PUBLIC_READ_ALL_BUCKETS' => 'false',
] + getenv();
$server = proc_open(
    [$phpBin, '-S', $host, $root . '/public/index.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes,
    $root . '/public',
    $env
);
register_shutdown_function(static function () use ($server, $tmpDir): void {
    proc_terminate($server);
    proc_close($server);
    removePath($tmpDir);
});
waitForServer($host);

$bucket = 'http-test-bucket';

[$status, $headers] = s3Request('PUT', $host, '/' . $bucket, '', $accessKey, $secretKey);
check($status === 200, 'CreateBucket (PUT /bucket) returns 200, got ' . $status);
check(is_dir($dataDir . '/' . $bucket), 'CreateBucket creates the bucket directory');
[$status] = s3Request('PUT', $host, '/' . $bucket, '', $accessKey, $secretKey);
check($status === 200, 'CreateBucket on an existing bucket succeeds');

$body = "hello etag\n";
[$status, $headers] = s3Request('PUT', $host, '/' . $bucket . '/hello.txt', $body, $accessKey, $secretKey);
check($status === 200, 'PutObject returns 200');
check(($headers['etag'] ?? '') === '"' . md5($body) . '"', 'PutObject returns the quoted MD5 as ETag, got ' . var_export($headers['etag'] ?? null, true));

[$status, , $xml] = s3Request('POST', $host, '/' . $bucket . '/multi.bin?uploads', '', $accessKey, $secretKey);
preg_match('#<UploadId>([^<]+)</UploadId>#', $xml, $m);
$uploadId = $m[1] ?? '';
check($status === 200 && $uploadId !== '', 'CreateMultipartUpload returns an upload ID');
$part = str_repeat('a', 1024);
[$status, $headers] = s3Request('PUT', $host, '/' . $bucket . '/multi.bin?partNumber=1&uploadId=' . $uploadId, $part, $accessKey, $secretKey);
check(($headers['etag'] ?? '') === '"' . md5($part) . '"', 'UploadPart returns the quoted MD5 as ETag');
$complete = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"' . md5($part) . '"</ETag></Part></CompleteMultipartUpload>';
[$status, $headers, $xml] = s3Request('POST', $host, '/' . $bucket . '/multi.bin?uploadId=' . $uploadId, $complete, $accessKey, $secretKey);
check($status === 200, 'CompleteMultipartUpload returns 200');
check(str_contains($xml, '<ETag>"' . md5($part) . '"</ETag>'), 'CompleteMultipartUpload result includes the ETag');

if ($failures > 0) {
    exit(1);
}
echo '[PASS] HTTP header tests passed' . PHP_EOL;

// --- helpers ---------------------------------------------------------------

function check(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    }
}

/**
 * @return array{0: int, 1: array<string, string>, 2: string}
 */
function s3Request(string $method, string $host, string $uri, string $body, string $accessKey, string $secretKey): array
{
    global $root, $phpBin;
    $payloadHash = hash('sha256', $body);
    $output = shell_exec(implode(' ', array_map('escapeshellarg', [
        $phpBin, $root . '/tests/integration/sigv4.php', 'auth', $method, 'http://' . $host . $uri, $accessKey, $secretKey, $payloadHash,
    ])));
    preg_match('/^x-amz-date=(.+)$/m', (string) $output, $date);
    preg_match('/^authorization=(.+)$/m', (string) $output, $auth);

    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode("\r\n", [
            'x-amz-date: ' . trim($date[1] ?? ''),
            'x-amz-content-sha256: ' . $payloadHash,
            'Authorization: ' . trim($auth[1] ?? ''),
            'Content-Length: ' . strlen($body),
        ]),
        'content' => $body,
        'ignore_errors' => true,
    ]]);
    $response = (string) @file_get_contents('http://' . $host . $uri, false, $context);
    $responseHeaders = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
    $status = 0;
    $headers = [];
    foreach ($responseHeaders as $line) {
        if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $s)) {
            $status = (int) $s[1];
            continue;
        }
        $pair = explode(':', $line, 2);
        if (count($pair) === 2) {
            $headers[strtolower(trim($pair[0]))] = trim($pair[1]);
        }
    }

    return [$status, $headers, $response];
}

function findFreePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name = stream_socket_get_name($socket, false);
    fclose($socket);

    return (int) substr((string) strrchr((string) $name, ':'), 1);
}

function waitForServer(string $host): void
{
    for ($i = 0; $i < 50; $i++) {
        $connection = @fsockopen('127.0.0.1', (int) explode(':', $host)[1]);
        if ($connection !== false) {
            fclose($connection);
            return;
        }
        usleep(100000);
    }
    fwrite(STDERR, '[FAIL] PHP built-in server did not start' . PHP_EOL);
    exit(1);
}

function removePath(string $path): void
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            removePath($path . '/' . $entry);
        }
    }
    @rmdir($path);
}
