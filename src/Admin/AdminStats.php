<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AdminStats
{
    private const CACHE_FILE = '.admin-stats.json';
    private const CACHE_TTL_SECONDS = 60;

    /**
     * The cheap part: directory status and bucket names. It never walks the
     * files, so pages can show it straight away; sizes come from measure().
     */
    public function scan(string $dataDir): array
    {
        $stats = [
            'data_dir' => $dataDir,
            'status' => 'ok',
            'bucket_count' => 0,
            'buckets' => [],
        ];

        if (!is_dir($dataDir)) {
            $stats['status'] = 'missing';
            return $stats;
        }
        if (!is_readable($dataDir)) {
            $stats['status'] = 'unreadable';
            return $stats;
        }
        if (!is_writable($dataDir)) {
            $stats['status'] = 'not_writable';
        }

        $bucketDirs = glob($dataDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($bucketDirs as $bucketDir) {
            if (basename($bucketDir) === '.multipart') {
                continue;
            }
            $stats['bucket_count']++;
            $stats['buckets'][] = ['name' => basename($bucketDir)];
        }

        return $stats;
    }

    /**
     * The slow part: object count and bytes per bucket and in total. Walking a
     * big data directory takes seconds, so the result is kept in a small file
     * for CACHE_TTL_SECONDS and shared by every page that shows sizes.
     *
     * @return array{object_count: int, total_bytes: int, buckets: list<array{name: string, object_count: int, total_bytes: int}>}
     */
    public function measure(string $dataDir): array
    {
        $totals = ['object_count' => 0, 'total_bytes' => 0, 'buckets' => []];
        if (!is_dir($dataDir) || !is_readable($dataDir)) {
            return $totals;
        }

        $cached = $this->readCache($dataDir);
        if ($cached !== null) {
            return $cached;
        }

        foreach ($this->scan($dataDir)['buckets'] as $bucket) {
            [$objectCount, $totalBytes] = self::measureDirectory($dataDir . '/' . $bucket['name']);
            $totals['object_count'] += $objectCount;
            $totals['total_bytes'] += $totalBytes;
            $totals['buckets'][] = ['name' => $bucket['name'], 'object_count' => $objectCount, 'total_bytes' => $totalBytes];
        }

        $this->writeCache($dataDir, $totals);

        return $totals;
    }

    /** Forget the cached sizes after the admin panel changes files. */
    public function invalidate(string $dataDir): void
    {
        @unlink($dataDir . '/' . self::CACHE_FILE);
    }

    /**
     * Count the objects under a directory and add up their sizes. Hidden
     * files are internal bookkeeping, not objects.
     *
     * @return array{0: int, 1: int} object count and total bytes
     */
    public static function measureDirectory(string $directory): array
    {
        $count = 0;
        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && !str_starts_with($file->getFilename(), '.')) {
                $count++;
                $bytes += $file->getSize();
            }
        }

        return [$count, $bytes];
    }

    private function readCache(string $dataDir): ?array
    {
        $contents = @file_get_contents($dataDir . '/' . self::CACHE_FILE);
        $cached = $contents === false ? null : json_decode($contents, true);
        if (!is_array($cached) || !isset($cached['at'], $cached['totals']) || !is_array($cached['totals'])) {
            return null;
        }
        if (time() - (int) $cached['at'] >= self::CACHE_TTL_SECONDS) {
            return null;
        }

        return $cached['totals'];
    }

    private function writeCache(string $dataDir, array $totals): void
    {
        // A read-only data directory just means no caching.
        @file_put_contents(
            $dataDir . '/' . self::CACHE_FILE,
            json_encode(['at' => time(), 'totals' => $totals]),
            LOCK_EX
        );
    }
}
