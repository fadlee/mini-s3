<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "[FAIL] Unable to locate project root\n");
    exit(1);
}
if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "[FAIL] ZipArchive extension is required\n");
    exit(1);
}

$version = (string) ($argv[1] ?? 'v0.0.0-test');
if ($version === '') {
    fail('version must not be empty');
}
$zipPath = (string) ($argv[2] ?? ($root . '/dist/mini-s3-' . $version . '.zip'));
$shouldBuild = $argc < 2;
if ($shouldBuild) {
    @unlink($zipPath);
    runPhp([$root . '/scripts/build-release.php', $version]);
}

if (!is_file($zipPath)) {
    fail('zip file was not found: ' . $zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fail('zip file cannot be opened');
}

$packageName = 'mini-s3-' . $version;
$entries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    $entries[] = $name;
}

assertContains($packageName . '/index.php', $entries, 'zip should contain index.php');
assertContains($packageName . '/.htaccess', $entries, 'zip should contain .htaccess');
foreach ([
    'README.md',
    'LICENSE',
    'config.example.php',
    'composer.json',
] as $path) {
    assertNotContains($packageName . '/' . $path, $entries, 'zip should not contain ' . $path);
}
foreach (['public/', 'src/', 'tests/', 'docs/', '.github/', 'data/', 'config/', '.env', 'dist/', 'vendor/', 'composer.lock'] as $prefix) {
    assertNoPrefix($packageName . '/' . $prefix, $entries, 'zip should not contain ' . $prefix);
}

$fileEntries = array_values(array_filter($entries, static fn(string $entry): bool => !str_ends_with($entry, '/')));
if (count($fileEntries) !== 2) {
    fail('zip should contain exactly 2 file(s), found ' . count($fileEntries));
}

$tmpDir = createTempDirectory($root . '/dist/.test-tmp', 'mini-s3-release-');
$zip->extractTo($tmpDir);
$zip->close();

$indexPath = $tmpDir . '/' . $packageName . '/index.php';
runPhpLint($indexPath);
$code = file_get_contents($indexPath);
if (!is_string($code) || !str_contains($code, "define('MINI_S3_VERSION', '" . $version . "');")) {
    fail('generated index.php should define MINI_S3_VERSION');
}

assertRequestHelperRejectsInvalidEntrypoint($root, $tmpDir);
smokeBundledEntrypoint($root, $indexPath, $tmpDir);

removePath($tmpDir);
echo "[PASS] Release archive test passed\n";

function smokeBundledEntrypoint(string $root, string $entrypoint, string $tmpDir): void
{
    $phpBin = getenv('PHP_BIN');
    $phpBin = is_string($phpBin) && $phpBin !== '' ? $phpBin : PHP_BINARY;
    $accessKey = 'release-test-key';
    $secretKey = 'release-test-secret';
    $bucket = 'release-smoke-' . bin2hex(random_bytes(4));
    $key = 'hello.txt';
    $host = 'mini-s3.test';
    $baseUrl = 'http://' . $host;
    $dataDir = $tmpDir . '/bundle-data';
    $env = [
        'MINI_S3_ENTRYPOINT' => $entrypoint,
        'MINI_S3_DATA_DIR' => $dataDir,
        'MINI_S3_CREDENTIALS_JSON' => json_encode([$accessKey => $secretKey], JSON_UNESCAPED_SLASHES),
        'MINI_S3_PUBLIC_READ_ALL_BUCKETS' => 'true',
    ];

    runRequest($root, 'GET', '/_', null, $tmpDir . '/bundle-admin.body', $tmpDir . '/bundle-admin.meta', ['Host: ' . $host], $env);
    assertEq('200', metaStatus($tmpDir . '/bundle-admin.meta', $phpBin), 'bundled admin installer route should succeed');
    assertFileContains('Install Mini S3', $tmpDir . '/bundle-admin.body', 'bundled admin installer should render setup page');

    $bodyFile = $tmpDir . '/bundle-hello.txt';
    file_put_contents($bodyFile, "hello bundled release\n");
    $payloadHash = hash_file('sha256', $bodyFile);
    [$amzDate, $authorization] = signHeaders($root, $phpBin, 'PUT', $baseUrl . '/' . $bucket . '/' . $key, $accessKey, $secretKey, $payloadHash);
    runRequest($root, 'PUT', '/' . $bucket . '/' . $key, $bodyFile, $tmpDir . '/bundle-put.body', $tmpDir . '/bundle-put.meta', [
        'Host: ' . $host,
        'x-amz-date: ' . $amzDate,
        'x-amz-content-sha256: ' . $payloadHash,
        'Authorization: ' . $authorization,
    ], $env);
    assertEq('200', metaStatus($tmpDir . '/bundle-put.meta', $phpBin), 'bundled signed PUT should succeed');

    runRequest($root, 'GET', '/' . $bucket . '/' . $key, null, $tmpDir . '/bundle-get.body', $tmpDir . '/bundle-get.meta', ['Host: ' . $host], $env);
    assertEq('200', metaStatus($tmpDir . '/bundle-get.meta', $phpBin), 'bundled public GET should succeed');
    assertSameFile($bodyFile, $tmpDir . '/bundle-get.body', 'bundled GET body differs from uploaded body');

    $emptyHash = hash('sha256', '');
    [$deleteDate, $deleteAuthorization] = signHeaders($root, $phpBin, 'DELETE', $baseUrl . '/' . $bucket . '/' . $key, $accessKey, $secretKey, $emptyHash);
    runRequest($root, 'DELETE', '/' . $bucket . '/' . $key, null, $tmpDir . '/bundle-delete.body', $tmpDir . '/bundle-delete.meta', [
        'Host: ' . $host,
        'x-amz-date: ' . $deleteDate,
        'x-amz-content-sha256: ' . $emptyHash,
        'Authorization: ' . $deleteAuthorization,
    ], $env);
    assertEq('204', metaStatus($tmpDir . '/bundle-delete.meta', $phpBin), 'bundled signed DELETE should succeed');
}

function assertRequestHelperRejectsInvalidEntrypoint(string $root, string $tmpDir): void
{
    [$exitCode, $stderr] = runRequestRaw(
        $root,
        'GET',
        '/_',
        null,
        $tmpDir . '/invalid-entrypoint.body',
        $tmpDir . '/invalid-entrypoint.meta',
        ['Host: mini-s3.test'],
        ['MINI_S3_ENTRYPOINT' => $tmpDir . '/missing-index.php']
    );

    if ($exitCode === 0) {
        fail('request helper should reject an invalid MINI_S3_ENTRYPOINT');
    }
    if (!str_contains($stderr, 'Entry point not found')) {
        fail('invalid entrypoint error should explain the missing entrypoint');
    }
}

function signHeaders(string $root, string $phpBin, string $method, string $fullUrl, string $accessKey, string $secretKey, string $payloadHash): array
{
    $output = runPhpCapture([$phpBin, $root . '/tests/integration/sigv4.php', 'auth', $method, $fullUrl, $accessKey, $secretKey, $payloadHash]);
    $values = [];
    foreach (explode("\n", trim($output)) as $line) {
        [$key, $value] = explode('=', $line, 2);
        $values[$key] = $value;
    }

    return [$values['x-amz-date'] ?? '', $values['authorization'] ?? ''];
}

function runRequest(string $root, string $method, string $uri, ?string $bodyFile, string $outBodyFile, string $outMetaFile, array $headers, array $env): void
{
    [$exitCode, $stderr] = runRequestRaw($root, $method, $uri, $bodyFile, $outBodyFile, $outMetaFile, $headers, $env);
    if ($exitCode !== 0) {
        fail('Request helper failed: ' . trim($stderr));
    }
}

function runRequestRaw(string $root, string $method, string $uri, ?string $bodyFile, string $outBodyFile, string $outMetaFile, array $headers, array $env): array
{
    $phpBin = getenv('PHP_BIN');
    $phpBin = is_string($phpBin) && $phpBin !== '' ? $phpBin : PHP_BINARY;
    $requestHelper = $root . '/tests/integration/request.php';
    $command = escapeshellarg($phpBin) . ' ' . escapeshellarg($requestHelper) . ' ' . escapeshellarg($method) . ' ' . escapeshellarg($uri) . ' ' . escapeshellarg($outMetaFile);
    foreach ($headers as $header) {
        $command .= ' ' . escapeshellarg($header);
    }
    $descriptors = [
        0 => $bodyFile !== null ? ['file', $bodyFile, 'r'] : ['pipe', 'r'],
        1 => ['file', $outBodyFile, 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, $root, array_merge($_ENV, $env));
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

    return [$exitCode, (string) $stderr];
}

function runPhp(array $args): void
{
    $command = escapeshellarg(PHP_BINARY);
    foreach ($args as $arg) {
        $command .= ' ' . escapeshellarg($arg);
    }
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

function runPhpCapture(array $args): string
{
    $command = '';
    foreach ($args as $arg) {
        $command .= ($command === '' ? '' : ' ') . escapeshellarg((string) $arg);
    }
    $output = shell_exec($command);
    if (!is_string($output)) {
        fail('Command failed: ' . $command);
    }

    return $output;
}

function runPhpLint(string $path): void
{
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

function metaStatus(string $metaFile, string $phpBin): string
{
    $json = runPhpCapture([$phpBin, '-r', '$m=json_decode(file_get_contents($argv[1]), true); echo (string)($m["status"] ?? "");', $metaFile]);

    return trim($json);
}

function fail(string $message): void
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
}

function assertEq(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        fail($message . ': expected=' . $expected . ' actual=' . $actual);
    }
}

function assertContains(string $expected, array $entries, string $message): void
{
    if (!in_array($expected, $entries, true)) {
        fail($message);
    }
}

function assertNotContains(string $expected, array $entries, string $message): void
{
    if (in_array($expected, $entries, true)) {
        fail($message);
    }
}

function assertNoPrefix(string $prefix, array $entries, string $message): void
{
    foreach ($entries as $entry) {
        if (str_starts_with($entry, $prefix)) {
            fail($message);
        }
    }
}

function assertFileContains(string $needle, string $file, string $message): void
{
    $contents = file_get_contents($file);
    if (!is_string($contents) || !str_contains($contents, $needle)) {
        fail($message);
    }
}

function assertSameFile(string $expectedPath, string $actualPath, string $message): void
{
    $expected = file_get_contents($expectedPath);
    $actual = file_get_contents($actualPath);
    if ($expected === false || $actual === false || $expected !== $actual) {
        fail($message);
    }
}

function createTempDirectory(string $parentDir, string $prefix): string
{
    if (!is_dir($parentDir) && !mkdir($parentDir, 0777, true) && !is_dir($parentDir)) {
        fail('Unable to create temporary parent directory');
    }
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
        unlink($path);
        return;
    }
    $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
    foreach ($iterator as $item) {
        removePath($item->getPathname());
    }
    rmdir($path);
}
