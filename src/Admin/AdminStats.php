<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class AdminStats
{
    public function scan(string $dataDir): array
    {
        $stats = [
            'data_dir' => $dataDir,
            'status' => 'ok',
            'bucket_count' => 0,
            'object_count' => 0,
            'total_bytes' => 0,
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
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($bucketDir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $stats['object_count']++;
                $stats['total_bytes'] += $file->getSize();
            }
        }

        return $stats;
    }
}
