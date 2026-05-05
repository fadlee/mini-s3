<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use RuntimeException;

final class AdminConfigWriter
{
    public function __construct(private readonly string $baseDir)
    {
    }

    public function buildConfig(array $input, array $existing = []): array
    {
        $dataDir = trim((string) ($input['data_dir'] ?? ''));
        if ($dataDir === '') {
            throw new RuntimeException('Data directory is required');
        }

        $accessKey = trim((string) ($input['access_key'] ?? ''));
        if ($accessKey === '') {
            throw new RuntimeException('Access key is required');
        }

        $secretKey = (string) ($input['secret_key'] ?? '');
        if ($secretKey === '') {
            throw new RuntimeException('Secret key is required');
        }

        $adminPasswordHash = trim((string) ($existing['ADMIN_PASSWORD_HASH'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');
        $passwordConfirm = (string) ($input['admin_password_confirm'] ?? '');
        if ($password !== '' || $passwordConfirm !== '' || $adminPasswordHash === '') {
            if ($password === '' || $password !== $passwordConfirm) {
                throw new RuntimeException('Admin passwords must match');
            }
            $adminPasswordHash = password_hash($password, PASSWORD_DEFAULT);
        }

        $maxRequestSize = $this->positiveInt($input['max_request_size'] ?? 100 * 1024 * 1024, 'Max request size');
        $clockSkewSeconds = $this->positiveInt($input['clock_skew_seconds'] ?? 900, 'Clock skew seconds');
        $maxPresignExpires = $this->positiveInt($input['max_presign_expires'] ?? 604800, 'Max presign expires');

        return [
            'DATA_DIR' => $dataDir,
            'MAX_REQUEST_SIZE' => $maxRequestSize,
            'CREDENTIALS' => [$accessKey => $secretKey],
            'ALLOW_LEGACY_ACCESS_KEY_ONLY' => false,
            'ALLOWED_ACCESS_KEYS' => [],
            'CLOCK_SKEW_SECONDS' => $clockSkewSeconds,
            'MAX_PRESIGN_EXPIRES' => $maxPresignExpires,
            'AUTH_DEBUG_LOG' => trim((string) ($input['auth_debug_log'] ?? '')),
            'ALLOW_HOST_CANDIDATE_FALLBACKS' => $this->checkbox($input, 'allow_host_candidate_fallbacks'),
            'PUBLIC_READ_ALL_BUCKETS' => $this->checkbox($input, 'public_read_all_buckets', (bool) ($existing['PUBLIC_READ_ALL_BUCKETS'] ?? true)),
            'ADMIN_PASSWORD_HASH' => $adminPasswordHash,
        ];
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
        if (!is_dir($configDir) && !mkdir($configDir, 0777, true) && !is_dir($configDir)) {
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
        if (!rename($tmpPath, $this->configPath())) {
            @unlink($tmpPath);
            throw new RuntimeException('Config file cannot be saved');
        }
    }

    public function ensureWritableDataDir(string $dataDir): void
    {
        if (!is_dir($dataDir) && !mkdir($dataDir, 0777, true) && !is_dir($dataDir)) {
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

    private function positiveInt(mixed $value, string $label): int
    {
        $int = (int) $value;
        if ($int < 1) {
            throw new RuntimeException($label . ' must be a positive integer');
        }

        return $int;
    }

    private function checkbox(array $input, string $key, bool $default = false): bool
    {
        if (!array_key_exists($key, $input)) {
            return $default;
        }

        return in_array((string) $input[$key], ['1', 'true', 'on', 'yes'], true);
    }
}
