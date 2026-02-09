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
        ];

        $modernConfigPath = $baseDir . '/config/config.php';
        if (is_file($modernConfigPath)) {
            $loaded = require $modernConfigPath;
            if (!is_array($loaded)) {
                throw new RuntimeException('Invalid config file: config/config.php must return an array');
            }
            $config = array_merge($config, $loaded);
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

                $config = array_merge($config, $legacy);
            }
        }

        $config['DATA_DIR'] = (string) $config['DATA_DIR'];
        $config['MAX_REQUEST_SIZE'] = max(1, (int) $config['MAX_REQUEST_SIZE']);
        $config['ALLOW_LEGACY_ACCESS_KEY_ONLY'] = (bool) $config['ALLOW_LEGACY_ACCESS_KEY_ONLY'];
        $config['CLOCK_SKEW_SECONDS'] = max(1, (int) $config['CLOCK_SKEW_SECONDS']);
        $config['MAX_PRESIGN_EXPIRES'] = max(1, (int) $config['MAX_PRESIGN_EXPIRES']);
        $config['AUTH_DEBUG_LOG'] = trim((string) ($config['AUTH_DEBUG_LOG'] ?? ''));
        $config['ALLOW_HOST_CANDIDATE_FALLBACKS'] = (bool) ($config['ALLOW_HOST_CANDIDATE_FALLBACKS'] ?? false);

        $credentials = [];
        foreach ((array) ($config['CREDENTIALS'] ?? []) as $accessKey => $secretKey) {
            $normalizedAccessKey = trim((string) $accessKey);
            if ($normalizedAccessKey === '') {
                continue;
            }
            $credentials[$normalizedAccessKey] = (string) $secretKey;
        }
        $config['CREDENTIALS'] = $credentials;

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
}
