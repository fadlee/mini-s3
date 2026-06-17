<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Admin/AdminConfigWriter.php';

use MiniS3\Admin\AdminConfigWriter;

$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected=" . var_export($expected, true) . " actual=" . var_export($actual, true) . PHP_EOL);
    }
}

function assertTrueValue(bool $actual, string $message): void
{
    assertSameValue(true, $actual, $message);
}

function assertThrowsMessage(callable $callback, string $needle, string $message): void
{
    global $failures;
    try {
        $callback();
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), $needle)) {
            return;
        }
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: wrong exception " . $e->getMessage() . PHP_EOL);
        return;
    }

    $failures++;
    fwrite(STDERR, "[FAIL] {$message}: expected exception" . PHP_EOL);
}

$base = sys_get_temp_dir() . '/mini-s3-writer-' . bin2hex(random_bytes(4));
$dataDir = $base . '/data';

$writer = new AdminConfigWriter($base);
$config = $writer->buildConfig([
    'admin_username' => 'owner',
    'admin_password' => 'secret-pass',
    'admin_password_confirm' => 'secret-pass',
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
    'max_request_size' => '1048576',
    'public_read_all_buckets' => '1',
    'auth_debug_log' => '',
    'allow_host_candidate_fallbacks' => '',
    'clock_skew_seconds' => '900',
    'max_presign_expires' => '604800',
]);

assertSameValue($dataDir, $config['DATA_DIR'], 'data dir is normalized');
assertSameValue(1048576, $config['MAX_REQUEST_SIZE'], 'max request size is integer');
assertSameValue(['access-one' => 'secret-one'], $config['CREDENTIALS'], 'credentials are built');
assertSameValue('owner', $config['ADMIN_USERNAME'], 'admin username is stored');
assertSameValue(true, password_verify('secret-pass', $config['ADMIN_PASSWORD_HASH']), 'admin password is hashed');
assertSameValue(true, $config['PUBLIC_READ_ALL_BUCKETS'], 'public read checkbox is parsed');

$defaultPublicReadConfig = $writer->buildConfig([
    'admin_username' => 'admin',
    'admin_password' => 'secret-pass',
    'admin_password_confirm' => 'secret-pass',
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
]);
assertSameValue(true, $defaultPublicReadConfig['PUBLIC_READ_ALL_BUCKETS'], 'public read defaults to true');

$existingUsernameConfig = $writer->buildConfig([
    'admin_password' => '',
    'admin_password_confirm' => '',
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
], [
    'ADMIN_USERNAME' => 'existing-admin',
    'ADMIN_PASSWORD_HASH' => $config['ADMIN_PASSWORD_HASH'],
]);
assertSameValue('existing-admin', $existingUsernameConfig['ADMIN_USERNAME'], 'admin username is preserved when omitted from existing config');

$writer->writeInstallerConfig($config);
assertTrueValue(is_file($base . '/config/config.php'), 'config file is written');
$loaded = require $base . '/config/config.php';
assertSameValue(['access-one' => 'secret-one'], $loaded['CREDENTIALS'], 'written config loads');
assertSameValue('owner', $loaded['ADMIN_USERNAME'], 'written config includes admin username');

assertThrowsMessage(fn() => $writer->writeInstallerConfig($config), 'already exists', 'installer refuses overwrite');
assertThrowsMessage(fn() => $writer->buildConfig([
    'admin_username' => 'owner',
    'admin_password' => 'one',
    'admin_password_confirm' => 'two',
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
]), 'match', 'password mismatch fails');
assertThrowsMessage(fn() => $writer->buildConfig([
    'admin_username' => '',
    'admin_password' => 'secret-pass',
    'admin_password_confirm' => 'secret-pass',
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
]), 'Admin username', 'empty admin username fails');
assertThrowsMessage(fn() => $writer->buildConfig([
    'admin_username' => 'owner',
    'admin_password' => 'secret-pass',
    'admin_password_confirm' => 'secret-pass',
    'data_dir' => $dataDir,
    'access_key' => '',
    'secret_key' => 'secret-one',
]), 'Access key', 'empty access key fails');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminConfigWriter tests passed" . PHP_EOL;
