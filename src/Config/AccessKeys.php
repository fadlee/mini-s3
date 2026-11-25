<?php

declare(strict_types=1);

namespace MiniS3\Config;

use RuntimeException;

/**
 * Access key model. CREDENTIALS entries come in two shapes:
 *
 *   'AKOLD' => 'secret',                          // legacy: read & write
 *   'AKNEW' => ['secret' => '...', 'access' => 'read', 'label' => 'CDN', 'created' => 1732500000],
 *
 * normalize() turns both into the second shape so the rest of the code only
 * deals with one form.
 */
final class AccessKeys
{
    public const READ = 'read';
    public const READ_WRITE = 'readwrite';

    private const ACCESS_KEY_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /**
     * @return array<string, array{secret: string, access: string, label: string, created: int}>
     */
    public static function normalize(array $credentials): array
    {
        $normalized = [];
        foreach ($credentials as $accessKey => $entry) {
            $accessKey = trim((string) $accessKey);
            if ($accessKey === '') {
                continue;
            }

            if (!is_array($entry)) {
                $normalized[$accessKey] = [
                    'secret' => (string) $entry,
                    'access' => self::READ_WRITE,
                    'label' => '',
                    'created' => 0,
                ];
                continue;
            }

            $secret = (string) ($entry['secret'] ?? '');
            if ($secret === '') {
                throw new RuntimeException('Misconfiguration: access key "' . $accessKey . '" has no secret');
            }
            $access = (string) ($entry['access'] ?? self::READ_WRITE);
            if (!self::isValidAccess($access)) {
                throw new RuntimeException('Misconfiguration: access key "' . $accessKey . '" has invalid access "' . $access . '" (use "read" or "readwrite")');
            }

            $normalized[$accessKey] = [
                'secret' => $secret,
                'access' => $access,
                'label' => trim((string) ($entry['label'] ?? '')),
                'created' => max(0, (int) ($entry['created'] ?? 0)),
            ];
        }

        return $normalized;
    }

    public static function isValidAccess(string $access): bool
    {
        return $access === self::READ || $access === self::READ_WRITE;
    }

    /**
     * @return array<string, string> access key => secret, as SigV4 needs it
     */
    public static function secrets(array $credentials): array
    {
        $secrets = [];
        foreach (self::normalize($credentials) as $accessKey => $entry) {
            $secrets[$accessKey] = $entry['secret'];
        }

        return $secrets;
    }

    /**
     * @return list<string>
     */
    public static function readOnlyKeys(array $credentials): array
    {
        $keys = [];
        foreach (self::normalize($credentials) as $accessKey => $entry) {
            if ($entry['access'] === self::READ) {
                $keys[] = (string) $accessKey;
            }
        }

        return $keys;
    }

    /**
     * 20-character access key: "AK" + 18 characters from [A-Z0-9].
     */
    public static function generateAccessKey(array $existing = []): string
    {
        $alphabetSize = strlen(self::ACCESS_KEY_ALPHABET);
        do {
            $key = 'AK';
            for ($i = 0; $i < 18; $i++) {
                $key .= self::ACCESS_KEY_ALPHABET[random_int(0, $alphabetSize - 1)];
            }
        } while (array_key_exists($key, $existing));

        return $key;
    }

    /**
     * 40-character secret from [A-Za-z0-9] (about 238 bits). Alphanumeric only,
     * so it is safe to paste into shells, .env files and config formats.
     */
    public static function generateSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $max = strlen($alphabet) - 1;
        $secret = '';
        for ($i = 0; $i < 40; $i++) {
            $secret .= $alphabet[random_int(0, $max)];
        }

        return $secret;
    }
}
