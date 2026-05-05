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
    $base = sys_get_temp_dir() . '/mini-s3-config-' . bin2hex(random_bytes(4));
    mkdir($base . '/config', 0777, true);
    if ($config !== []) {
        file_put_contents($base . '/config/config.php', '<?php return ' . var_export($config, true) . ';');
    }

    return $base;
}

$base = tempProject([
    'CREDENTIALS' => ['file-key' => 'file-secret'],
    'PUBLIC_READ_ALL_BUCKETS' => true,
    'ADMIN_PASSWORD_HASH' => '$2y$10$abcdefghijklmnopqrstuuJ8CmYLcOeO9mRXuQzknW4f4mSb1zZ9K',
]);
$config = ConfigLoader::load($base);
assertSameValue(['file-key' => 'file-secret'], $config['CREDENTIALS'], 'config file credentials load');
assertSameValue(true, $config['PUBLIC_READ_ALL_BUCKETS'], 'config file public read loads');
assertSameValue('$2y$10$abcdefghijklmnopqrstuuJ8CmYLcOeO9mRXuQzknW4f4mSb1zZ9K', $config['ADMIN_PASSWORD_HASH'], 'admin password hash loads');

withEnv([
    'MINI_S3_CREDENTIALS_JSON' => '{"env-key":"env-secret"}',
    'MINI_S3_PUBLIC_READ_ALL_BUCKETS' => 'false',
], function () use ($base): void {
    $config = ConfigLoader::load($base);
    assertSameValue(['env-key' => 'env-secret'], $config['CREDENTIALS'], 'env credentials override file');
    assertSameValue(false, $config['PUBLIC_READ_ALL_BUCKETS'], 'env boolean override file');
});

withEnv(['MINI_S3_CREDENTIALS_JSON' => '{bad-json'], function () use ($base): void {
    assertThrows(fn() => ConfigLoader::load($base), 'invalid credential JSON fails');
});

$emptyBase = tempProject([]);
assertThrows(fn() => ConfigLoader::load($emptyBase), 'empty credentials fail closed');

$defaultBase = tempProject(['CREDENTIALS' => ['default-key' => 'default-secret']]);
$defaultConfig = ConfigLoader::load($defaultBase);
assertSameValue(true, $defaultConfig['PUBLIC_READ_ALL_BUCKETS'], 'public read defaults to true');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] ConfigLoader tests passed" . PHP_EOL;
