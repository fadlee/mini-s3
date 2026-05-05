<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminUpgradeService
{
    private const REPO_OWNER = 'fadlee';
    private const REPO_NAME = 'mini-s3';
    private const MAX_INDEX_BYTES = 5242880;
    private const CACHE_TTL_SECONDS = 21600;

    public function __construct(
        private readonly string $baseDir,
        private readonly string $dataDir,
        private readonly string $entryFile,
        private readonly mixed $metadataFetcher = null,
        private readonly mixed $downloader = null
    ) {
    }

    public function checkLatest(string $currentVersion): array
    {
        try {
            $metadata = $this->metadataFetcher === null
                ? $this->fetchLatestRelease()
                : ($this->metadataFetcher)();
        } catch (\Throwable $e) {
            return [
                'state' => 'error',
                'message' => 'Unable to check GitHub releases: ' . $e->getMessage(),
                'currentVersion' => $currentVersion,
                'latestVersion' => null,
                'assetUrl' => null,
            ];
        }

        $latestTag = $this->releaseTag($metadata);
        if ($latestTag === null) {
            return [
                'state' => 'error',
                'message' => 'Latest GitHub release does not have a valid version tag.',
                'currentVersion' => $currentVersion,
                'latestVersion' => null,
                'assetUrl' => null,
            ];
        }

        if ($this->compareVersions($currentVersion, $latestTag) >= 0) {
            return [
                'state' => 'up_to_date',
                'message' => 'Mini S3 is up to date.',
                'currentVersion' => $currentVersion,
                'latestVersion' => $latestTag,
                'assetUrl' => null,
            ];
        }

        $assetUrl = $this->assetUrl($metadata, $latestTag);
        if ($assetUrl === null) {
            return [
                'state' => 'error',
                'message' => 'Latest GitHub release does not include the expected zip asset.',
                'currentVersion' => $currentVersion,
                'latestVersion' => $latestTag,
                'assetUrl' => null,
            ];
        }

        return [
            'state' => 'update_available',
            'message' => 'Update available: ' . $latestTag,
            'currentVersion' => $currentVersion,
            'latestVersion' => $latestTag,
            'assetUrl' => $assetUrl,
        ];
    }

    public function cachedStatus(string $currentVersion, bool $force = false): array
    {
        if (!$force) {
            $cached = $this->readCachedStatus();
            if ($cached !== null) {
                return $cached;
            }
        }

        $status = $this->checkLatest($currentVersion);
        $this->writeCachedStatus($status);

        return $status;
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

    public function upgrade(string $currentVersion, string $latestVersion, string $assetUrl): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return ['ok' => false, 'message' => 'ZipArchive extension is required for auto-upgrade.'];
        }
        if ($this->compareVersions($currentVersion, $latestVersion) >= 0) {
            return ['ok' => false, 'message' => 'No newer version is available.'];
        }
        if (!is_file($this->entryFile) || !is_writable($this->entryFile) || !is_writable(dirname($this->entryFile))) {
            return ['ok' => false, 'message' => 'Current index.php or its directory is not writable.'];
        }

        $tmpDir = $this->dataDir . '/.upgrade-tmp';
        if (!$this->ensureDirectory($tmpDir)) {
            return ['ok' => false, 'message' => 'Temporary upgrade directory cannot be created or written.'];
        }

        $zipPath = $tmpDir . '/mini-s3-' . $latestVersion . '.zip';
        $newPath = $tmpDir . '/index-' . $latestVersion . '.php';

        try {
            $this->download($assetUrl, $zipPath);
            $code = $this->extractIndex($zipPath, $latestVersion);
            $validation = $this->validateReleaseIndex($code, $latestVersion);
            if (!$validation['valid']) {
                return ['ok' => false, 'message' => $validation['message']];
            }
            if (file_put_contents($newPath, $code) === false) {
                return ['ok' => false, 'message' => 'Unable to write downloaded index.php.'];
            }

            $backupPath = $this->backupCurrentIndex();
            $siblingNewPath = $this->entryFile . '.new';
            if (!copy($newPath, $siblingNewPath)) {
                return ['ok' => false, 'message' => 'Unable to stage new index.php next to current entry file.'];
            }
            if (!rename($siblingNewPath, $this->entryFile)) {
                @unlink($siblingNewPath);
                $restored = @copy($backupPath, $this->entryFile);
                return ['ok' => false, 'message' => $restored ? 'Upgrade failed and rollback succeeded.' : 'Upgrade failed and rollback failed. Restore backup manually.'];
            }

            return ['ok' => true, 'message' => 'Mini S3 upgraded to ' . $latestVersion . '.', 'backupPath' => $backupPath];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            @unlink($zipPath);
            @unlink($newPath);
        }
    }

    private function normalizeVersion(string $version): string
    {
        return ltrim($version, 'vV');
    }

    private function download(string $url, string $destination): void
    {
        if ($this->downloader !== null) {
            ($this->downloader)($url, $destination);
            return;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: mini-s3-admin-upgrade\r\n",
                'timeout' => 30,
            ],
        ]);
        $contents = file_get_contents($url, false, $context);
        if ($contents === false) {
            throw new \RuntimeException('Download failed.');
        }
        if (file_put_contents($destination, $contents) === false) {
            throw new \RuntimeException('Unable to save downloaded release asset.');
        }
    }

    private function extractIndex(string $zipPath, string $version): string
    {
        $expectedPath = 'mini-s3-' . $version . '/index.php';
        $zip = new \ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open release zip.');
        }
        $code = $zip->getFromName($expectedPath);
        $zip->close();
        if ($code === false) {
            throw new \RuntimeException('Release zip does not contain the expected index.php.');
        }

        return $code;
    }

    private function backupCurrentIndex(): string
    {
        $backupDir = $this->dataDir . '/.upgrade-backups/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
        if (!$this->ensureDirectory($backupDir)) {
            throw new \RuntimeException('Backup directory is not writable.');
        }
        $backupPath = $backupDir . '/index.php';
        if (!copy($this->entryFile, $backupPath)) {
            throw new \RuntimeException('Unable to back up current index.php.');
        }

        return $backupPath;
    }

    private function ensureDirectory(string $path): bool
    {
        if (!is_dir($path) && !mkdir($path, 0777, true)) {
            return false;
        }

        return is_writable($path);
    }

    private function readCachedStatus(): ?array
    {
        $path = $this->cachePath();
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return null;
        }
        $cachedAt = (int) ($decoded['cachedAt'] ?? 0);
        if ($cachedAt < time() - self::CACHE_TTL_SECONDS) {
            return null;
        }
        $status = $decoded['status'] ?? null;

        return is_array($status) ? $status : null;
    }

    private function writeCachedStatus(array $status): void
    {
        $dir = dirname($this->cachePath());
        if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
            return;
        }
        if (!is_writable($dir)) {
            return;
        }
        file_put_contents($this->cachePath(), json_encode([
            'cachedAt' => time(),
            'status' => $status,
        ], JSON_UNESCAPED_SLASHES));
    }

    private function cachePath(): string
    {
        return $this->dataDir . '/.upgrade-cache/latest.json';
    }

    private function fetchLatestRelease(): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: mini-s3-admin-upgrade\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 5,
            ],
        ]);
        $url = 'https://api.github.com/repos/' . self::REPO_OWNER . '/' . self::REPO_NAME . '/releases/latest';
        $body = file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('request failed');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('invalid JSON response');
        }

        return $decoded;
    }
}
