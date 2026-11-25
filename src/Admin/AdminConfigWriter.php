<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use MiniS3\Config\AccessKeys;
use MiniS3\Storage\FileStorage;
use RuntimeException;

/**
 * Builds and writes config/config.php. Every build method starts from the
 * config as stored on disk ($existing, from ConfigLoader::loadFile) so values
 * that only come from environment variables are never written to the file,
 * and keys this class does not manage are kept.
 */
final class AdminConfigWriter
{
    public const MIN_PASSWORD_LENGTH = 8;
    public const DEFAULT_MAX_REQUEST_SIZE = 100 * 1024 * 1024;
    public const DEFAULT_CLOCK_SKEW_SECONDS = 900;
    public const DEFAULT_MAX_PRESIGN_EXPIRES = 604800;

    public function __construct(private readonly string $baseDir)
    {
    }

    /**
     * First-run config: admin account, data directory and (unless credentials
     * come from MINI_S3_CREDENTIALS_JSON) one generated read/write access key.
     *
     * @return array{0: array, 1: ?string} the config and the generated access key
     */
    public function buildInstallerConfig(array $input, bool $generateKey, ?int $now = null): array
    {
        $adminUsername = trim((string) ($input['admin_username'] ?? ''));
        if ($adminUsername === '') {
            throw new RuntimeException('Admin username is required');
        }
        $passwordHash = $this->hashNewPassword(
            (string) ($input['admin_password'] ?? ''),
            (string) ($input['admin_password_confirm'] ?? ''),
            'Admin password'
        );

        $config = [
            'DATA_DIR' => $this->requiredDataDir($input),
            'MAX_REQUEST_SIZE' => self::DEFAULT_MAX_REQUEST_SIZE,
            'CREDENTIALS' => [],
            'ALLOW_LEGACY_ACCESS_KEY_ONLY' => false,
            'ALLOWED_ACCESS_KEYS' => [],
            'CLOCK_SKEW_SECONDS' => self::DEFAULT_CLOCK_SKEW_SECONDS,
            'MAX_PRESIGN_EXPIRES' => self::DEFAULT_MAX_PRESIGN_EXPIRES,
            'AUTH_DEBUG_LOG' => '',
            'ALLOW_HOST_CANDIDATE_FALLBACKS' => false,
            'PUBLIC_READ_ALL_BUCKETS' => false,
            'ADMIN_USERNAME' => $adminUsername,
            'ADMIN_PASSWORD_HASH' => $passwordHash,
        ];

        $accessKey = null;
        if ($generateKey) {
            [$config, $accessKey] = $this->addKey($config, 'Default', AccessKeys::READ_WRITE, $now);
        }

        return [$config, $accessKey];
    }

    /**
     * Settings page save. A field missing from $input (for example a disabled
     * field locked by an environment variable) keeps its existing value.
     */
    public function buildSettingsConfig(array $input, array $existing): array
    {
        $config = $existing;

        if (array_key_exists('data_dir', $input)) {
            $config['DATA_DIR'] = $this->requiredDataDir($input);
        }
        if (array_key_exists('max_request_size', $input)) {
            $config['MAX_REQUEST_SIZE'] = $this->positiveInt($input['max_request_size'], 'Max upload size');
        }
        if (array_key_exists('clock_skew_seconds', $input)) {
            $config['CLOCK_SKEW_SECONDS'] = $this->positiveInt($input['clock_skew_seconds'], 'Clock skew');
        }
        if (array_key_exists('max_presign_expires', $input)) {
            $config['MAX_PRESIGN_EXPIRES'] = $this->positiveInt($input['max_presign_expires'], 'Max presigned URL lifetime');
        }
        if (array_key_exists('public_read_all_buckets', $input)) {
            $config['PUBLIC_READ_ALL_BUCKETS'] = $this->checkbox($input['public_read_all_buckets']);
        }
        if (array_key_exists('allow_host_candidate_fallbacks', $input)) {
            $config['ALLOW_HOST_CANDIDATE_FALLBACKS'] = $this->checkbox($input['allow_host_candidate_fallbacks']);
        }

        return $config;
    }

    /**
     * @return array{0: array, 1: string} the config and the new access key
     */
    public function addKey(array $existing, string $label, string $access, ?int $now = null): array
    {
        $credentials = AccessKeys::normalize((array) ($existing['CREDENTIALS'] ?? []));
        $accessKey = AccessKeys::generateAccessKey($credentials);
        $credentials[$accessKey] = [
            'secret' => AccessKeys::generateSecret(),
            'access' => $this->validAccess($access),
            'label' => $this->validLabel($label),
            'created' => $now ?? time(),
        ];
        $existing['CREDENTIALS'] = $credentials;

        return [$existing, $accessKey];
    }

    public function updateKey(array $existing, string $accessKey, string $label, string $access): array
    {
        $credentials = $this->existingCredentials($existing, $accessKey);
        $credentials[$accessKey]['label'] = $this->validLabel($label);
        $credentials[$accessKey]['access'] = $this->validAccess($access);
        $existing['CREDENTIALS'] = $credentials;

        return $existing;
    }

    public function deleteKey(array $existing, string $accessKey): array
    {
        $credentials = $this->existingCredentials($existing, $accessKey);
        unset($credentials[$accessKey]);
        $hasLegacyAllowance = (bool) ($existing['ALLOW_LEGACY_ACCESS_KEY_ONLY'] ?? false)
            && (array) ($existing['ALLOWED_ACCESS_KEYS'] ?? []) !== [];
        if ($credentials === [] && !$hasLegacyAllowance) {
            throw new RuntimeException('The last access key cannot be deleted. Create a new key first.');
        }
        $existing['CREDENTIALS'] = $credentials;

        return $existing;
    }

    public function buildAdminAccountConfig(array $input, array $existing): array
    {
        $adminUsername = trim((string) ($input['admin_username'] ?? ''));
        if ($adminUsername === '') {
            throw new RuntimeException('Admin username is required');
        }

        $adminPasswordHash = trim((string) ($existing['ADMIN_PASSWORD_HASH'] ?? ''));
        if ($adminPasswordHash === '') {
            throw new RuntimeException('Admin password is not configured');
        }

        $password = (string) ($input['new_password'] ?? '');
        $passwordConfirm = (string) ($input['new_password_confirm'] ?? '');
        if ($password !== '' || $passwordConfirm !== '') {
            $adminPasswordHash = $this->hashNewPassword($password, $passwordConfirm, 'New admin password');
        }

        $config = $existing;
        $config['ADMIN_USERNAME'] = $adminUsername;
        $config['ADMIN_PASSWORD_HASH'] = $adminPasswordHash;

        return $config;
    }

    public function writeInstallerConfig(array $config): void
    {
        $path = $this->configPath();
        if (is_file($path)) {
            throw new RuntimeException('Config file already exists; log in instead');
        }

        $this->ensureWritableDataDir((string) $config['DATA_DIR']);
        $this->writeConfig($config);
    }

    public function writeConfig(array $config): void
    {
        $configDir = dirname($this->configPath());
        if (!is_dir($configDir) && !mkdir($configDir, 0700, true) && !is_dir($configDir)) {
            throw new RuntimeException('Config directory cannot be created');
        }
        if (!is_writable($configDir)) {
            throw new RuntimeException('Config directory is not writable');
        }

        $tmpPath = $this->configPath() . '.tmp.' . bin2hex(random_bytes(4));
        $php = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        if (file_put_contents($tmpPath, $php, LOCK_EX) === false) {
            throw new RuntimeException('Config file cannot be written');
        }
        // The file holds secrets: keep it private where the host allows it.
        @chmod($tmpPath, 0600);
        if (!rename($tmpPath, $this->configPath())) {
            @unlink($tmpPath);
            throw new RuntimeException('Config file cannot be saved');
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->configPath(), true);
        }
    }

    public function ensureWritableDataDir(string $dataDir): void
    {
        try {
            (new FileStorage($dataDir))->ensureDataDirExists();
        } catch (RuntimeException) {
            throw new RuntimeException('Data directory cannot be created');
        }
        if (!is_readable($dataDir) || !is_writable($dataDir)) {
            throw new RuntimeException('Data directory must be readable and writable');
        }
    }

    private function configPath(): string
    {
        return $this->baseDir . '/config/config.php';
    }

    private function requiredDataDir(array $input): string
    {
        $dataDir = trim((string) ($input['data_dir'] ?? ''));
        if ($dataDir === '') {
            throw new RuntimeException('Data directory is required');
        }

        return $dataDir;
    }

    private function hashNewPassword(string $password, string $confirm, string $label): string
    {
        if ($password === '') {
            throw new RuntimeException($label . ' is required');
        }
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new RuntimeException($label . ' must be at least ' . self::MIN_PASSWORD_LENGTH . ' characters');
        }
        if ($password !== $confirm) {
            throw new RuntimeException('Passwords must match');
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    private function existingCredentials(array $existing, string $accessKey): array
    {
        $credentials = AccessKeys::normalize((array) ($existing['CREDENTIALS'] ?? []));
        if (!array_key_exists($accessKey, $credentials)) {
            throw new RuntimeException('Access key not found');
        }

        return $credentials;
    }

    private function validAccess(string $access): string
    {
        if (!AccessKeys::isValidAccess($access)) {
            throw new RuntimeException('Permission must be "read" or "readwrite"');
        }

        return $access;
    }

    private function validLabel(string $label): string
    {
        $label = trim($label);
        if (strlen($label) > 64) {
            throw new RuntimeException('Label must be 64 characters or fewer');
        }

        return $label;
    }

    private function positiveInt(mixed $value, string $label): int
    {
        $string = trim((string) $value);
        if (!ctype_digit($string) || (int) $string < 1) {
            throw new RuntimeException($label . ' must be a positive whole number');
        }

        return (int) $string;
    }

    private function checkbox(mixed $value): bool
    {
        return in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
    }
}
