<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Config/AccessKeys.php';
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

// --- Installer ---------------------------------------------------------------
[$config, $firstKey] = $writer->buildInstallerConfig([
    'admin_username' => 'owner',
    'admin_password' => 'secret-pass',
    'admin_password_confirm' => 'secret-pass',
    'data_dir' => $dataDir,
], true, 1700000000);
assertSameValue($dataDir, $config['DATA_DIR'], 'installer stores data dir');
assertSameValue('owner', $config['ADMIN_USERNAME'], 'installer stores admin username');
assertTrueValue(password_verify('secret-pass', $config['ADMIN_PASSWORD_HASH']), 'installer hashes admin password');
assertSameValue(false, $config['PUBLIC_READ_ALL_BUCKETS'], 'installer keeps buckets private');
assertSameValue(1, preg_match('/^AK[A-Z0-9]{18}$/', (string) $firstKey), 'installer generates an access key');
assertSameValue('readwrite', $config['CREDENTIALS'][$firstKey]['access'], 'first key is read/write');
assertSameValue('Default', $config['CREDENTIALS'][$firstKey]['label'], 'first key is labelled');
assertSameValue(1700000000, $config['CREDENTIALS'][$firstKey]['created'], 'first key records creation time');
assertSameValue(40, strlen($config['CREDENTIALS'][$firstKey]['secret']), 'first key has a generated secret');

[$envConfig, $noKey] = $writer->buildInstallerConfig([
    'admin_username' => 'owner',
    'admin_password' => 'secret-pass',
    'admin_password_confirm' => 'secret-pass',
    'data_dir' => $dataDir,
], false);
assertSameValue(null, $noKey, 'installer skips key generation when credentials come from env');
assertSameValue([], $envConfig['CREDENTIALS'], 'installer writes no credentials when credentials come from env');

$installerInput = ['admin_username' => 'owner', 'admin_password' => 'secret-pass', 'admin_password_confirm' => 'secret-pass', 'data_dir' => $dataDir];
assertThrowsMessage(fn() => $writer->buildInstallerConfig(['admin_password_confirm' => 'other-pass'] + $installerInput, true), 'match', 'password mismatch fails');
assertThrowsMessage(fn() => $writer->buildInstallerConfig(['admin_password' => 'short', 'admin_password_confirm' => 'short'] + $installerInput, true), 'at least 8', 'short password fails');
assertThrowsMessage(fn() => $writer->buildInstallerConfig(['admin_username' => ' '] + $installerInput, true), 'Admin username', 'empty admin username fails');
assertThrowsMessage(fn() => $writer->buildInstallerConfig(['data_dir' => ''] + $installerInput, true), 'Data directory', 'empty data dir fails');

// --- Settings ----------------------------------------------------------------
$existingFull = [
    'DATA_DIR' => $dataDir,
    'MAX_REQUEST_SIZE' => 1048576,
    'CREDENTIALS' => ['first-key' => 'first-secret', 'second-key' => 'second-secret'],
    'ALLOW_LEGACY_ACCESS_KEY_ONLY' => true,
    'ALLOWED_ACCESS_KEYS' => ['legacy-key'],
    'CLOCK_SKEW_SECONDS' => 900,
    'MAX_PRESIGN_EXPIRES' => 604800,
    'AUTH_DEBUG_LOG' => '/tmp/debug.log',
    'ALLOW_HOST_CANDIDATE_FALLBACKS' => false,
    'PUBLIC_READ_ALL_BUCKETS' => true,
    'ADMIN_USERNAME' => 'owner',
    'ADMIN_PASSWORD_HASH' => $config['ADMIN_PASSWORD_HASH'],
    'GITHUB_TOKEN' => 'ghp_example',
    'CUSTOM_SETTING' => 'keep-me',
];
$settings = $writer->buildSettingsConfig([
    'data_dir' => $dataDir . '/new',
    'max_request_size' => '2048',
    'public_read_all_buckets' => '0',
    'allow_host_candidate_fallbacks' => '1',
    'clock_skew_seconds' => '60',
    'max_presign_expires' => '3600',
    'auth_debug_log' => '/var/www/public/shell.php',
], $existingFull);
assertSameValue($dataDir . '/new', $settings['DATA_DIR'], 'settings store data dir');
assertSameValue(2048, $settings['MAX_REQUEST_SIZE'], 'settings store max request size');
assertSameValue(false, $settings['PUBLIC_READ_ALL_BUCKETS'], 'public read posted as 0 turns off');
assertSameValue(true, $settings['ALLOW_HOST_CANDIDATE_FALLBACKS'], 'host fallback checkbox is parsed');
assertSameValue(60, $settings['CLOCK_SKEW_SECONDS'], 'settings store clock skew');
assertSameValue(3600, $settings['MAX_PRESIGN_EXPIRES'], 'settings store presign lifetime');
assertSameValue('/tmp/debug.log', $settings['AUTH_DEBUG_LOG'], 'auth debug log cannot be changed from the settings form');
assertSameValue($existingFull['CREDENTIALS'], $settings['CREDENTIALS'], 'settings save keeps credentials untouched');
assertSameValue('ghp_example', $settings['GITHUB_TOKEN'], 'github token is preserved');
assertSameValue(true, $settings['ALLOW_LEGACY_ACCESS_KEY_ONLY'], 'legacy access key flag is preserved');
assertSameValue(['legacy-key'], $settings['ALLOWED_ACCESS_KEYS'], 'allowed access keys are preserved');
assertSameValue('keep-me', $settings['CUSTOM_SETTING'], 'unknown config keys are preserved');
assertSameValue('owner', $settings['ADMIN_USERNAME'], 'admin username is preserved');

$locked = $writer->buildSettingsConfig(['max_request_size' => '4096'], $existingFull);
assertSameValue($dataDir, $locked['DATA_DIR'], 'missing (env-locked) data dir keeps existing value');
assertSameValue(true, $locked['PUBLIC_READ_ALL_BUCKETS'], 'missing (env-locked) checkbox keeps existing value');
$fileOnly = array_diff_key($existingFull, ['GITHUB_TOKEN' => true]);
assertSameValue(false, array_key_exists('GITHUB_TOKEN', $writer->buildSettingsConfig(['max_request_size' => '1'], $fileOnly)), 'no env-only value is added to the file');
assertThrowsMessage(fn() => $writer->buildSettingsConfig(['max_request_size' => 'abc'], $existingFull), 'positive whole number', 'invalid number fails');
assertThrowsMessage(fn() => $writer->buildSettingsConfig(['clock_skew_seconds' => '0'], $existingFull), 'positive whole number', 'zero fails');
assertThrowsMessage(fn() => $writer->buildSettingsConfig(['data_dir' => ''], $existingFull), 'Data directory', 'empty data dir fails');

// --- Access keys -------------------------------------------------------------
[$withKey, $newKey] = $writer->addKey($existingFull, ' CDN ', 'read', 1700000001);
assertSameValue(['secret' => 'first-secret', 'access' => 'readwrite', 'label' => '', 'created' => 0], $withKey['CREDENTIALS']['first-key'], 'adding a key migrates legacy entries');
assertSameValue('read', $withKey['CREDENTIALS'][$newKey]['access'], 'new key is read-only');
assertSameValue('CDN', $withKey['CREDENTIALS'][$newKey]['label'], 'new key label is trimmed');
assertSameValue(3, count($withKey['CREDENTIALS']), 'existing keys are kept');
assertThrowsMessage(fn() => $writer->addKey($existingFull, 'x', 'admin'), 'Permission', 'invalid permission fails');
assertThrowsMessage(fn() => $writer->addKey($existingFull, str_repeat('x', 65), 'read'), '64 characters', 'long label fails');

$updated = $writer->updateKey($withKey, $newKey, 'Backups', 'readwrite');
assertSameValue('Backups', $updated['CREDENTIALS'][$newKey]['label'], 'key label is updated');
assertSameValue('readwrite', $updated['CREDENTIALS'][$newKey]['access'], 'key permission is updated');
assertSameValue($withKey['CREDENTIALS'][$newKey]['secret'], $updated['CREDENTIALS'][$newKey]['secret'], 'key secret is unchanged by update');
assertThrowsMessage(fn() => $writer->updateKey($withKey, 'missing', 'x', 'read'), 'not found', 'updating a missing key fails');

$deleted = $writer->deleteKey($withKey, 'first-key');
assertSameValue(false, array_key_exists('first-key', $deleted['CREDENTIALS']), 'key is deleted');
$single = ['CREDENTIALS' => ['only' => 'secret']];
assertThrowsMessage(fn() => $writer->deleteKey($single, 'only'), 'last access key', 'last key cannot be deleted');
assertSameValue([], $writer->deleteKey($single + ['ALLOW_LEGACY_ACCESS_KEY_ONLY' => true, 'ALLOWED_ACCESS_KEYS' => ['k']], 'only')['CREDENTIALS'], 'last key can go when legacy access keys exist');

// --- Admin account -----------------------------------------------------------
$renamedAccountConfig = $writer->buildAdminAccountConfig([
    'admin_username' => 'new-owner',
    'new_password' => '',
    'new_password_confirm' => '',
], $config);
assertSameValue('new-owner', $renamedAccountConfig['ADMIN_USERNAME'], 'admin account update stores username');
assertSameValue($config['ADMIN_PASSWORD_HASH'], $renamedAccountConfig['ADMIN_PASSWORD_HASH'], 'blank new password keeps the password');
$newPasswordConfig = $writer->buildAdminAccountConfig([
    'admin_username' => 'new-owner',
    'new_password' => 'new-secret-pass',
    'new_password_confirm' => 'new-secret-pass',
], $config);
assertTrueValue(password_verify('new-secret-pass', $newPasswordConfig['ADMIN_PASSWORD_HASH']), 'admin account update hashes new password');
assertThrowsMessage(fn() => $writer->buildAdminAccountConfig(['admin_username' => 'a', 'new_password' => 'short', 'new_password_confirm' => 'short'], $config), 'at least 8', 'short new password fails');

// --- Writing -----------------------------------------------------------------
$writer->writeInstallerConfig($config);
$configFile = $base . '/config/config.php';
assertTrueValue(is_file($configFile), 'config file is written');
assertSameValue('0600', substr(sprintf('%o', fileperms($configFile)), -4), 'config file is private (0600)');
$loaded = require $configFile;
assertSameValue($config['CREDENTIALS'], $loaded['CREDENTIALS'], 'written config loads');
assertSameValue('owner', $loaded['ADMIN_USERNAME'], 'written config includes admin username');
assertThrowsMessage(fn() => $writer->writeInstallerConfig($config), 'already exists', 'installer refuses overwrite');

$protectedDir = $base . '/protected-data';
$writer->ensureWritableDataDir($protectedDir);
assertSameValue(true, str_contains((string) @file_get_contents($protectedDir . '/.htaccess'), 'Require all denied'), 'ensureWritableDataDir writes data dir .htaccess');

if ($failures > 0) {
    exit(1);
}

echo "[PASS] AdminConfigWriter tests passed" . PHP_EOL;
