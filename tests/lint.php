<?php

declare(strict_types=1);

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "[FAIL] Unable to locate project root\n");
    exit(1);
}

$phpBin = getenv('PHP_BIN');
$phpBin = is_string($phpBin) && $phpBin !== '' ? $phpBin : PHP_BINARY;

$files = [
    $root . '/config.example.php',
    $root . '/public/index.php',
    $root . '/src/Admin/AdminAuth.php',
    $root . '/src/Admin/AdminConfigWriter.php',
    $root . '/src/Admin/AdminFileExplorer.php',
    $root . '/src/Admin/AdminRenderer.php',
    $root . '/src/Admin/AdminRouter.php',
    $root . '/src/Admin/AdminStats.php',
    $root . '/src/Admin/AdminUpgradeService.php',
    $root . '/src/Auth/AuthException.php',
    $root . '/src/Auth/SigV4Authenticator.php',
    $root . '/src/Config/ConfigLoader.php',
    $root . '/src/Http/RequestContext.php',
    $root . '/src/S3/S3Response.php',
    $root . '/src/S3/RequestValidator.php',
    $root . '/src/S3/S3Router.php',
    $root . '/src/Storage/FileStorage.php',
    $root . '/tests/integration/request.php',
    $root . '/tests/integration/run.php',
    $root . '/tests/integration/sigv4.php',
    $root . '/tests/lint.php',
    $root . '/tests/release-archive.php',
    $root . '/tests/unit/admin-auth.php',
    $root . '/tests/unit/admin-config-writer.php',
    $root . '/tests/unit/admin-file-explorer.php',
    $root . '/tests/unit/admin-renderer.php',
    $root . '/tests/unit/admin-stats.php',
    $root . '/tests/unit/admin-upgrade-service.php',
    $root . '/tests/unit/config-loader.php',
    $root . '/tests/unit/file-storage.php',
    $root . '/tests/unit/request-validator.php',
];

foreach ($files as $file) {
    $command = escapeshellarg($phpBin) . ' -l ' . escapeshellarg($file);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

echo "[PASS] PHP lint passed\n";
