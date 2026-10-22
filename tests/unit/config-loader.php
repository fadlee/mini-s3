<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/ConfigLoader.php';

use MiniS3\Config\ConfigLoader;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

function assertThrows(callable $callback, string $message): void
{
    global $failures;
    try {
        $callback();
    } catch (Throwable $e) {
        return;
    }

    $failures++;
    fwrite(STDERR, "[FAIL] {$message}: expected exception" . PHP_EOL);
}

function withEnv(array $env, callable $callback): void
{
    $keys = array_keys($env);
    $previous = [];
    foreach ($keys as $key) {
        $previous[$key] = getenv($key);
        putenv($key . '=' . $env[$key]);
        $_ENV[$key] = $env[$key];
    }

    try {
        $callback();
    } finally {
        foreach ($keys as $key) {
            if ($previous[$key] === false) {
                putenv($key);
                unset($_ENV[$key]);
            } else {
                putenv($key . '=' . $previous[$key]);
                $_ENV[$key] = $previous[$key];
            }
        }
    }
}

function tempProject(array $config): string
{
    $base = createTempDirectory('mini-s3-config-');
    mkdir($base . '/config', 0777, true);
    if ($config !== []) {
        file_put_contents($base . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
    }

    return $base;
}

function createTempDirectory(string $prefix): string
{
    $parent = __DIR__ . '/../../data/.test-tmp';
    if (!is_dir($parent) && !mkdir($parent, 0777, true) && !is_dir($parent)) {
        throw new RuntimeException('Unable to create test temp parent directory');
    }
    $path = $parent . '/' . $prefix . bin2hex(random_bytes(6));
    if (!mkdir($path, 0777, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create test temp directory');
    }
    return $path;
}

$base = tempProject([
    'CREDENTIALS' => ['file-key' => 'file-secret'],
    'PUBLIC_READ_ALL_BUCKETS' => true,
    'ADMIN_PASSWORD_HASH' => '$2y$10$abcdefghijklmnopqrstuuJ8CmYLcOeO9mRXuQzknW4f4mSb1zZ9K',
]);
$config = ConfigLoader::load($base);
assertSameValue(['file-key' => 'file-secret'], $config['CREDENTIALS'], 'config file credentials load');
assertSameValue(true, $config['PUBLIC_READ_ALL_BUCKETS'], 'config file public read loads');
assertSameValue('admin', $config['ADMIN_USERNAME'], 'admin username defaults for existing config');
assertSameValue('$2y$10$abcdefghijklmnopqrstuuJ8CmYLcOeO9mRXuQzknW4f4mSb1zZ9K', $config['ADMIN_PASSWORD_HASH'], 'admin password hash loads');
assertSameValue('', $config['GITHUB_TOKEN'], 'github token defaults empty');

withEnv([
    'MINI_S3_CREDENTIALS_JSON' => '{"env-key":"env-secret"}',
    'MINI_S3_PUBLIC_READ_ALL_BUCKETS' => 'false',
    'MINI_S3_GITHUB_TOKEN' => 'env-token',
    'MINI_S3_PUBLIC_CACHE_CONTROL' => 'public, max-age=60',
], function () use ($base): void {
    $config = ConfigLoader::load($base);
    assertSameValue(['env-key' => 'env-secret'], $config['CREDENTIALS'], 'env credentials override file');
    assertSameValue(false, $config['PUBLIC_READ_ALL_BUCKETS'], 'env boolean override file');
    assertSameValue('env-token', $config['GITHUB_TOKEN'], 'env github token override file');
    assertSameValue('public, max-age=60', $config['PUBLIC_CACHE_CONTROL'], 'env public cache control override file');
});

withEnv(['MINI_S3_CREDENTIALS_JSON' => '{bad-json'], function () use ($base): void {
    assertThrows(fn() => ConfigLoader::load($base), 'invalid credential JSON fails');
});

$rawBase = tempProject([
    'CREDENTIALS' => ['file-key' => 'file-secret'],
    'GITHUB_TOKEN' => 'file-token',
]);
withEnv([
    'MINI_S3_GITHUB_TOKEN' => 'env-token',
    'MINI_S3_CREDENTIALS_JSON' => '{"env-key":"env-secret"}',
    'MINI_S3_DATA_DIR' => '/env/data',
], function () use ($rawBase): void {
    $raw = ConfigLoader::loadFile($rawBase);
    assertSameValue('file-token', $raw['GITHUB_TOKEN'], 'loadFile ignores env github token');
    assertSameValue(['file-key' => 'file-secret'], $raw['CREDENTIALS'], 'loadFile ignores env credentials');
    assertSameValue(false, array_key_exists('DATA_DIR', $raw), 'loadFile merges no defaults');
    assertSameValue('env-token', ConfigLoader::load($rawBase)['GITHUB_TOKEN'], 'load still applies env override');
});
assertSameValue([], ConfigLoader::loadFile(tempProject([])), 'loadFile returns empty array for empty config dir');
$missingBase = createTempDirectory('mini-s3-config-missing-');
assertSameValue([], ConfigLoader::loadFile($missingBase), 'loadFile returns empty array when config file is missing');
$badBase = createTempDirectory('mini-s3-config-bad-');
mkdir($badBase . '/config', 0777, true);
file_put_contents($badBase . '/config/config.php', '<?php return "not-an-array";');
assertThrows(fn() => ConfigLoader::loadFile($badBase), 'loadFile rejects non-array config');

$emptyBase = tempProject([]);
assertThrows(fn() => ConfigLoader::load($emptyBase), 'empty credentials fail closed');

$defaultBase = tempProject(['CREDENTIALS' => ['default-key' => 'default-secret']]);
$defaultConfig = ConfigLoader::load($defaultBase);
assertSameValue(true, $defaultConfig['PUBLIC_READ_ALL_BUCKETS'], 'public read defaults to true');
assertSameValue('public, max-age=3600', $defaultConfig['PUBLIC_CACHE_CONTROL'], 'public cache control has a default');

$cacheBase = tempProject([
    'CREDENTIALS' => ['cache-key' => 'cache-secret'],
    'PUBLIC_CACHE_CONTROL' => '  public, max-age=31536000, immutable ',
]);
assertSameValue('public, max-age=31536000, immutable', ConfigLoader::load($cacheBase)['PUBLIC_CACHE_CONTROL'], 'public cache control loads from config file');
$headerInjectionBase = tempProject([
    'CREDENTIALS' => ['cache-key' => 'cache-secret'],
    'PUBLIC_CACHE_CONTROL' => "public\r\nX-Evil: 1",
]);
assertSameValue('public, max-age=3600', ConfigLoader::load($headerInjectionBase)['PUBLIC_CACHE_CONTROL'], 'public cache control with line breaks falls back to default');
assertSameValue('admin', $defaultConfig['ADMIN_USERNAME'], 'admin username defaults to admin');

$usernameBase = tempProject([
    'CREDENTIALS' => ['username-key' => 'username-secret'],
    'ADMIN_USERNAME' => 'owner',
    'GITHUB_TOKEN' => 'config-token',
]);
$usernameConfig = ConfigLoader::load($usernameBase);
assertSameValue('owner', $usernameConfig['ADMIN_USERNAME'], 'admin username loads from config');
assertSameValue('config-token', $usernameConfig['GITHUB_TOKEN'], 'github token loads from config');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] ConfigLoader tests passed" . PHP_EOL;
