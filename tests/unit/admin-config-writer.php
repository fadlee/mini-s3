<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Storage/FileStorage.php';
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

$publicReadOffConfig = $writer->buildConfig([
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
    'public_read_all_buckets' => '0',
], [
    'PUBLIC_READ_ALL_BUCKETS' => true,
    'ADMIN_USERNAME' => 'owner',
    'ADMIN_PASSWORD_HASH' => $config['ADMIN_PASSWORD_HASH'],
]);
assertSameValue(false, $publicReadOffConfig['PUBLIC_READ_ALL_BUCKETS'], 'public read unchecked (posted as 0) turns off over existing true');

// Config page save must use the file-only array as base: env-only values are not in $existing,
// so they must not be written. The router passes ConfigLoader::loadFile() here.
$fileOnlyBase = [
    'CREDENTIALS' => ['access-one' => 'secret-one'],
    'ADMIN_USERNAME' => 'owner',
    'ADMIN_PASSWORD_HASH' => $config['ADMIN_PASSWORD_HASH'],
    'PUBLIC_READ_ALL_BUCKETS' => true,
    'GITHUB_TOKEN' => 'file-token',
];
$fileOnlySave = $writer->buildConfig([
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
], $fileOnlyBase);
assertSameValue('file-token', $fileOnlySave['GITHUB_TOKEN'], 'file-only github token is kept on save');
$noTokenSave = $writer->buildConfig([
    'data_dir' => $dataDir,
    'access_key' => 'access-one',
    'secret_key' => 'secret-one',
], array_diff_key($fileOnlyBase, ['GITHUB_TOKEN' => true]));
assertSameValue(false, array_key_exists('GITHUB_TOKEN', $noTokenSave), 'no env-style github token is added when file has none');

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
$runtimeOnlyConfig = $writer->buildConfig([
    'data_dir' => $dataDir,
    'access_key' => 'access-two',
    'secret_key' => 'secret-two',
], $config);
assertSameValue('owner', $runtimeOnlyConfig['ADMIN_USERNAME'], 'runtime config save preserves existing admin username');
assertSameValue($config['ADMIN_PASSWORD_HASH'], $runtimeOnlyConfig['ADMIN_PASSWORD_HASH'], 'runtime config save preserves existing admin password hash');

$renamedAccountConfig = $writer->buildAdminAccountConfig([
    'admin_username' => 'new-owner',
    'new_password' => '',
    'new_password_confirm' => '',
], $config);
assertSameValue('new-owner', $renamedAccountConfig['ADMIN_USERNAME'], 'admin account update stores username');
assertSameValue($config['ADMIN_PASSWORD_HASH'], $renamedAccountConfig['ADMIN_PASSWORD_HASH'], 'admin account update preserves password when new password is blank');

$newPasswordConfig = $writer->buildAdminAccountConfig([
    'admin_username' => 'new-owner',
    'new_password' => 'new-secret-pass',
    'new_password_confirm' => 'new-secret-pass',
], $config);
assertSameValue(true, password_verify('new-secret-pass', $newPasswordConfig['ADMIN_PASSWORD_HASH']), 'admin account update hashes new password');


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

$existingFull = [
    'DATA_DIR' => $dataDir,
    'MAX_REQUEST_SIZE' => 1048576,
    'CREDENTIALS' => ['first-key' => 'first-secret', 'second-key' => 'second-secret'],
    'ALLOW_LEGACY_ACCESS_KEY_ONLY' => true,
    'ALLOWED_ACCESS_KEYS' => ['legacy-key'],
    'CLOCK_SKEW_SECONDS' => 900,
    'MAX_PRESIGN_EXPIRES' => 604800,
    'AUTH_DEBUG_LOG' => '',
    'ALLOW_HOST_CANDIDATE_FALLBACKS' => false,
    'PUBLIC_READ_ALL_BUCKETS' => true,
    'ADMIN_USERNAME' => 'owner',
    'ADMIN_PASSWORD_HASH' => $config['ADMIN_PASSWORD_HASH'],
    'GITHUB_TOKEN' => 'ghp_example',
    'CUSTOM_SETTING' => 'keep-me',
];
$preserved = $writer->buildConfig([
    'data_dir' => $dataDir,
    'access_key' => 'new-key',
    'secret_key' => 'new-secret',
], $existingFull);
assertSameValue('ghp_example', $preserved['GITHUB_TOKEN'], 'github token is preserved on config save');
assertSameValue(true, $preserved['ALLOW_LEGACY_ACCESS_KEY_ONLY'], 'legacy access key flag is preserved on config save');
assertSameValue(['legacy-key'], $preserved['ALLOWED_ACCESS_KEYS'], 'allowed access keys are preserved on config save');
assertSameValue('keep-me', $preserved['CUSTOM_SETTING'], 'unknown config keys are preserved on config save');
assertSameValue(['new-key' => 'new-secret', 'second-key' => 'second-secret'], $preserved['CREDENTIALS'], 'first credential is replaced and other credentials are kept');
assertSameValue('owner', $preserved['ADMIN_USERNAME'], 'admin username is preserved on config save');

$duplicateKey = $writer->buildConfig([
    'data_dir' => $dataDir,
    'access_key' => 'second-key',
    'secret_key' => 'form-secret',
], $existingFull);
assertSameValue(['second-key' => 'form-secret'], $duplicateKey['CREDENTIALS'], 'form credential wins when it matches another existing key');

$protectedDir = $base . '/protected-data';
$writer->ensureWritableDataDir($protectedDir);
assertSameValue(true, str_contains((string) @file_get_contents($protectedDir . '/.htaccess'), 'Require all denied'), 'ensureWritableDataDir writes data dir .htaccess');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminConfigWriter tests passed" . PHP_EOL;
