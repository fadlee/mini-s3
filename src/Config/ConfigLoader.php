<?php

declare(strict_types=1);

namespace MiniS3\Config;

use RuntimeException;

final class ConfigLoader
{
    public static function load(string $baseDir): array
    {
        $config = [
            'DATA_DIR' => $baseDir . '/data',
            'MAX_REQUEST_SIZE' => 100 * 1024 * 1024,
            'CREDENTIALS' => [],
            'ALLOW_LEGACY_ACCESS_KEY_ONLY' => false,
            'ALLOWED_ACCESS_KEYS' => [],
            'CLOCK_SKEW_SECONDS' => 900,
            'MAX_PRESIGN_EXPIRES' => 604800,
            'AUTH_DEBUG_LOG' => '',
            'ALLOW_HOST_CANDIDATE_FALLBACKS' => false,
            'PUBLIC_READ_ALL_BUCKETS' => false,
            'ADMIN_USERNAME' => 'admin',
            'ADMIN_PASSWORD_HASH' => '',
            'GITHUB_TOKEN' => '',
        ];

        $modernConfigPath = $baseDir . '/config/config.php';
        if (is_file($modernConfigPath)) {
            $config = array_merge($config, self::loadFile($baseDir));
        } else {
            $legacyConfigPath = $baseDir . '/config.php';
            if (is_file($legacyConfigPath)) {
                require_once $legacyConfigPath;

                $legacy = [];
                if (defined('DATA_DIR')) {
                    $legacy['DATA_DIR'] = (string) constant('DATA_DIR');
                }
                if (defined('MAX_REQUEST_SIZE')) {
                    $legacy['MAX_REQUEST_SIZE'] = (int) constant('MAX_REQUEST_SIZE');
                }
                if (defined('ALLOW_LEGACY_ACCESS_KEY_ONLY')) {
                    $legacy['ALLOW_LEGACY_ACCESS_KEY_ONLY'] = (bool) constant('ALLOW_LEGACY_ACCESS_KEY_ONLY');
                }
                if (defined('ALLOWED_ACCESS_KEYS')) {
                    $legacy['ALLOWED_ACCESS_KEYS'] = (array) constant('ALLOWED_ACCESS_KEYS');
                }
                if (defined('CREDENTIALS')) {
                    $legacy['CREDENTIALS'] = (array) constant('CREDENTIALS');
                }
                if (defined('ADMIN_USERNAME')) {
                    $legacy['ADMIN_USERNAME'] = (string) constant('ADMIN_USERNAME');
                }

                $config = array_merge($config, $legacy);
            }
        }

        $config = self::applyEnvironmentOverrides($config);

        $config['DATA_DIR'] = (string) $config['DATA_DIR'];
        $config['MAX_REQUEST_SIZE'] = max(1, (int) $config['MAX_REQUEST_SIZE']);
        $config['ALLOW_LEGACY_ACCESS_KEY_ONLY'] = (bool) $config['ALLOW_LEGACY_ACCESS_KEY_ONLY'];
        $config['CLOCK_SKEW_SECONDS'] = max(1, (int) $config['CLOCK_SKEW_SECONDS']);
        $config['MAX_PRESIGN_EXPIRES'] = max(1, (int) $config['MAX_PRESIGN_EXPIRES']);
        $config['AUTH_DEBUG_LOG'] = trim((string) ($config['AUTH_DEBUG_LOG'] ?? ''));
        $config['ALLOW_HOST_CANDIDATE_FALLBACKS'] = (bool) ($config['ALLOW_HOST_CANDIDATE_FALLBACKS'] ?? false);
        $config['PUBLIC_READ_ALL_BUCKETS'] = (bool) ($config['PUBLIC_READ_ALL_BUCKETS'] ?? false);
        $config['ADMIN_USERNAME'] = trim((string) ($config['ADMIN_USERNAME'] ?? 'admin'));
        if ($config['ADMIN_USERNAME'] === '') {
            $config['ADMIN_USERNAME'] = 'admin';
        }
        $config['ADMIN_PASSWORD_HASH'] = trim((string) ($config['ADMIN_PASSWORD_HASH'] ?? ''));
        $config['GITHUB_TOKEN'] = trim((string) ($config['GITHUB_TOKEN'] ?? ''));

        // Always the normalized shape: access key => [secret, access, label, created].
        $config['CREDENTIALS'] = AccessKeys::normalize((array) ($config['CREDENTIALS'] ?? []));

        $allowedAccessKeys = [];
        foreach ((array) ($config['ALLOWED_ACCESS_KEYS'] ?? []) as $accessKey) {
            $normalizedAccessKey = trim((string) $accessKey);
            if ($normalizedAccessKey === '') {
                continue;
            }
            $allowedAccessKeys[] = $normalizedAccessKey;
        }
        $config['ALLOWED_ACCESS_KEYS'] = array_values(array_unique($allowedAccessKeys));

        if ($config['CREDENTIALS'] === []) {
            $hasLegacyAllowance = $config['ALLOW_LEGACY_ACCESS_KEY_ONLY'] && $config['ALLOWED_ACCESS_KEYS'] !== [];
            if (!$hasLegacyAllowance) {
                throw new RuntimeException(
                    'Misconfiguration: CREDENTIALS is empty. Configure credentials or enable ALLOW_LEGACY_ACCESS_KEY_ONLY with ALLOWED_ACCESS_KEYS.'
                );
            }
        }

        return $config;
    }

    /**
     * Raw array from config/config.php, without defaults, env overrides or normalization.
     * Returns [] when the file is missing.
     */
    public static function loadFile(string $baseDir): array
    {
        $modernConfigPath = $baseDir . '/config/config.php';
        if (!is_file($modernConfigPath)) {
            return [];
        }

        $loaded = require $modernConfigPath;
        if (!is_array($loaded)) {
            throw new RuntimeException('Invalid config file: config/config.php must return an array');
        }

        return $loaded;
    }

    /**
     * Environment variable => config key. Values set here override config.php
     * and cannot be edited from the admin UI.
     */
    private const ENV_MAP = [
        'MINI_S3_DATA_DIR' => 'DATA_DIR',
        'MINI_S3_AUTH_DEBUG_LOG' => 'AUTH_DEBUG_LOG',
        'MINI_S3_GITHUB_TOKEN' => 'GITHUB_TOKEN',
        'MINI_S3_MAX_REQUEST_SIZE' => 'MAX_REQUEST_SIZE',
        'MINI_S3_PUBLIC_READ_ALL_BUCKETS' => 'PUBLIC_READ_ALL_BUCKETS',
        'MINI_S3_ALLOW_HOST_CANDIDATE_FALLBACKS' => 'ALLOW_HOST_CANDIDATE_FALLBACKS',
        'MINI_S3_CREDENTIALS_JSON' => 'CREDENTIALS',
    ];

    private const BOOLEAN_KEYS = ['PUBLIC_READ_ALL_BUCKETS', 'ALLOW_HOST_CANDIDATE_FALLBACKS'];

    /**
     * @return array<string, string> config key => environment variable, for every variable that is set
     */
    public static function envOverriddenKeys(): array
    {
        $keys = [];
        foreach (self::ENV_MAP as $envName => $configKey) {
            if (self::env($envName) !== null) {
                $keys[$configKey] = $envName;
            }
        }

        return $keys;
    }

    private static function applyEnvironmentOverrides(array $config): array
    {
        foreach (self::ENV_MAP as $envName => $configKey) {
            $value = self::env($envName);
            if ($value === null) {
                continue;
            }

            if ($configKey === 'CREDENTIALS') {
                $decoded = json_decode($value, true);
                if (!is_array($decoded)) {
                    throw new RuntimeException('Misconfiguration: MINI_S3_CREDENTIALS_JSON must be a JSON object');
                }
                $config['CREDENTIALS'] = $decoded;
            } elseif ($configKey === 'MAX_REQUEST_SIZE') {
                $config[$configKey] = (int) $value;
            } elseif (in_array($configKey, self::BOOLEAN_KEYS, true)) {
                $config[$configKey] = self::parseBoolean($value);
            } else {
                $config[$configKey] = $value;
            }
        }

        return $config;
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }

    private static function parseBoolean(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }
}
