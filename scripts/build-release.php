<?php

declare(strict_types=1);

$version = $argv[1] ?? '';
if ($version === '') {
    fwrite(STDERR, "Usage: php scripts/build-release.php <version>\n");
    exit(1);
}

$root = realpath(__DIR__ . '/..');
if ($root === false) {
    throw new RuntimeException('Unable to locate project root');
}

$sourceFiles = [
    'src/Config/ConfigLoader.php',
    'src/Admin/AdminAuth.php',
    'src/Admin/AdminConfigWriter.php',
    'src/Admin/AdminStats.php',
    'src/Admin/AdminUpgradeService.php',
    'src/Admin/AdminFileExplorer.php',
    'src/Admin/AdminRenderer.php',
    'src/Admin/AdminRouter.php',
    'src/Auth/AuthException.php',
    'src/Auth/SigV4Authenticator.php',
    'src/Http/RequestContext.php',
    'src/Storage/FileStorage.php',
    'src/S3/S3Response.php',
    'src/S3/RequestValidator.php',
    'src/S3/S3Router.php',
];

foreach (['public/index.php', 'public/.htaccess', 'src'] as $path) {
    if (!file_exists($root . '/' . $path)) {
        throw new RuntimeException('Required release path missing: ' . $path);
    }
}
foreach ($sourceFiles as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Required source file missing: ' . $path);
    }
}
if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('ZipArchive extension is required to build release archives');
}

$distDir = $root . '/dist';
$packageName = 'mini-s3-' . $version;
$zipPath = $distDir . '/' . $packageName . '.zip';
$stageParent = $distDir . '/.stage-' . $packageName;
$stageDir = $stageParent . '/' . $packageName;

removePath($stageParent);
ensureDirectory($stageDir);
ensureDirectory($distDir);

$bundlePath = $stageDir . '/index.php';
$bundle = "<?php\n\ndeclare(strict_types=1);\n\nnamespace {\n";
$bundle .= "    define('BASE_PATH', __DIR__);\n";
$bundle .= "    define('MINI_S3_VERSION', '" . addslashes($version) . "');\n";
$bundle .= "}\n\n";

foreach ($sourceFiles as $path) {
    $bundle .= appendPhpBody($root . '/' . $path);
}
$bundle .= buildEntrypointBody($root . '/public/index.php');

file_put_contents($bundlePath, $bundle);

$lintCommand = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($bundlePath);
passthru($lintCommand, $lintExitCode);
if ($lintExitCode !== 0) {
    exit($lintExitCode);
}

copy($root . '/public/.htaccess', $stageDir . '/.htaccess');
if (is_file($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Unable to create zip archive');
}
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stageParent, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);
foreach ($iterator as $item) {
    $absolutePath = $item->getPathname();
    $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($stageParent) + 1));
    if ($item->isDir()) {
        $zip->addEmptyDir($relativePath);
        continue;
    }
    $zip->addFile($absolutePath, $relativePath);
}
$zip->close();

removePath($stageParent);

echo 'Created ' . $zipPath . PHP_EOL;

function appendPhpBody(string $path): string
{
    $code = file_get_contents($path);
    if ($code === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    $code = preg_replace('/^<\?php\s*/', '', $code, 1) ?? $code;
    $code = preg_replace('/^declare\(strict_types=1\);\s*/', '', $code, 1) ?? $code;
    $code = preg_replace('/\?>\s*$/', '', $code, 1) ?? $code;
    if (preg_match('/^namespace\s+([^;]+);\s*/', $code, $matches) === 1) {
        $namespace = trim($matches[1]);
        $code = preg_replace('/^namespace\s+[^;]+;\s*/', '', $code, 1) ?? $code;
        return 'namespace ' . $namespace . " {\n" . rtrim($code) . "\n}\n\n";
    }
    return rtrim($code) . "\n\n";
}

function buildEntrypointBody(string $path): string
{
    $code = file_get_contents($path);
    if ($code === false) {
        throw new RuntimeException('Unable to read ' . $path);
    }
    $code = preg_replace('/^<\?php\s*/', '', $code, 1) ?? $code;
    $code = preg_replace('/^declare\(strict_types=1\);\s*/', '', $code, 1) ?? $code;
    $basePathLine = "define('BASE_PATH', realpath(__DIR__.'/..'));";
    $code = str_replace([$basePathLine . "\n\n", $basePathLine . "\n", $basePathLine], '', $code);
    $lines = explode("\n", $code);
    $keptLines = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, 'require_once BASE_PATH . ') && str_contains($trimmed, '/src/')) {
            continue;
        }
        $keptLines[] = $line;
    }
    $code = trim(implode("\n", $keptLines));
    $body = "namespace {\n";
    foreach (explode("\n", $code) as $line) {
        $body .= '    ' . rtrim($line) . "\n";
    }
    return $body . "}\n";
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create directory: ' . $path);
    }
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
