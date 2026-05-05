<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminUpgradeService
{
    private const REPO_OWNER = 'fadlee';
    private const REPO_NAME = 'mini-s3';
    private const MAX_INDEX_BYTES = 5242880;

    public function __construct(
        private readonly string $baseDir,
        private readonly string $dataDir,
        private readonly string $entryFile
    ) {
    }

    public function status(?string $currentVersion): array
    {
        if ($currentVersion === null || $currentVersion === '') {
            return [
                'state' => 'unavailable',
                'message' => 'Auto-upgrade is only available for generated release installs.',
                'currentVersion' => null,
                'latestVersion' => null,
                'assetUrl' => null,
            ];
        }

        return [
            'state' => 'unknown',
            'message' => 'Update check has not run yet.',
            'currentVersion' => $currentVersion,
            'latestVersion' => null,
            'assetUrl' => null,
        ];
    }

    public function compareVersions(string $current, string $latest): int
    {
        return version_compare($this->normalizeVersion($current), $this->normalizeVersion($latest));
    }

    public function releaseTag(array $metadata): ?string
    {
        $tag = (string) ($metadata['tag_name'] ?? '');
        if (!preg_match('/^v?\d+\.\d+\.\d+$/', $tag)) {
            return null;
        }

        return str_starts_with($tag, 'v') ? $tag : 'v' . $tag;
    }

    public function assetUrl(array $metadata, string $tag): ?string
    {
        $expectedName = 'mini-s3-' . $tag . '.zip';
        foreach ((array) ($metadata['assets'] ?? []) as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            if (($asset['name'] ?? '') !== $expectedName) {
                continue;
            }
            $url = (string) ($asset['browser_download_url'] ?? '');
            return $url === '' ? null : $url;
        }

        return null;
    }

    public function validateReleaseIndex(string $code, string $expectedVersion): array
    {
        if ($code === '' || strlen($code) > self::MAX_INDEX_BYTES) {
            return ['valid' => false, 'message' => 'Release index.php has an invalid size.'];
        }
        if (!str_starts_with($code, '<?php')) {
            return ['valid' => false, 'message' => 'Release index.php is not a PHP file.'];
        }
        if (!str_contains($code, "define('MINI_S3_VERSION', '" . $expectedVersion . "');")) {
            return ['valid' => false, 'message' => 'Release index.php does not match the expected version.'];
        }
        if (!str_contains($code, 'AdminRouter') || !str_contains($code, 'S3Router')) {
            return ['valid' => false, 'message' => 'Release index.php does not look like a Mini S3 runtime file.'];
        }

        return ['valid' => true, 'message' => 'Release index.php is valid.'];
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }
}
