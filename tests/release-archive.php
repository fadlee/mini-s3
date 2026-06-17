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

$version = 'v0.0.0-test';
$zipPath = $root . '/dist/mini-s3-' . $version . '.zip';
@unlink($zipPath);
runPhp([$root . '/scripts/build-release.php', $version]);

if (!is_file($zipPath)) {
    fail('zip file was not created');
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fail('zip file cannot be opened');
}

$entries = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = (string) $zip->getNameIndex($i);
    $entries[] = $name;
}

assertContains('mini-s3-' . $version . '/index.php', $entries, 'zip should contain index.php');
assertContains('mini-s3-' . $version . '/.htaccess', $entries, 'zip should contain .htaccess');
foreach ([
    'README.md',
    'LICENSE',
    'config.example.php',
    'composer.json',
] as $path) {
    assertNotContains('mini-s3-' . $version . '/' . $path, $entries, 'zip should not contain ' . $path);
}
foreach (['public/', 'src/', 'tests/', 'docs/', '.github/', 'data/', 'config/', '.env', 'dist/', 'vendor/', 'composer.lock'] as $prefix) {
    assertNoPrefix('mini-s3-' . $version . '/' . $prefix, $entries, 'zip should not contain ' . $prefix);
}

$fileEntries = array_values(array_filter($entries, static fn(string $entry): bool => !str_ends_with($entry, '/')));
if (count($fileEntries) !== 2) {
    fail('zip should contain exactly 2 file(s), found ' . count($fileEntries));
}

$tmpDir = createTempDirectory($root . '/dist/.test-tmp', 'mini-s3-release-');
$zip->extractTo($tmpDir);
$zip->close();

$indexPath = $tmpDir . '/mini-s3-' . $version . '/index.php';
runPhpLint($indexPath);
$code = file_get_contents($indexPath);
if (!is_string($code) || !str_contains($code, "define('MINI_S3_VERSION', '" . $version . "');")) {
    fail('generated index.php should define MINI_S3_VERSION');
}

removePath($tmpDir);
echo "[PASS] Release archive test passed\n";

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

function runPhpLint(string $path): void
{
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

function fail(string $message): void
{
    fwrite(STDERR, '[FAIL] ' . $message . PHP_EOL);
    exit(1);
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
