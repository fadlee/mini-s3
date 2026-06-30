<?php

declare(strict_types=1);

namespace MiniS3\Admin;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class AdminFileExplorer
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function listBuckets(): array
    {
        if (!is_dir($this->dataDir)) {
            return [];
        }

        $buckets = [];
        $dirs = glob($this->dataDir . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            $name = basename($dir);
            if ($name === '.multipart') {
                continue;
            }

            $objectCount = 0;
            $totalBytes = 0;
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || str_starts_with($file->getFilename(), '.')) {
                    continue;
                }

                $objectCount++;
                $totalBytes += $file->getSize();
            }

            $buckets[] = [
                'name' => $name,
                'object_count' => $objectCount,
                'total_bytes' => $totalBytes,
                'modified' => filemtime($dir) ?: 0,
            ];
        }

        usort($buckets, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return $buckets;
    }

    public function listObjects(string $bucket, string $prefix = ''): array
    {
        $bucketDir = $this->bucketPath($bucket);
        $prefix = $this->normalizeRelativePath($prefix);
        $scanDir = $this->resolveInsideBucket($bucket, $prefix, true);

        $folders = [];
        $files = [];
        $items = new FilesystemIterator($scanDir, FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            $name = $item->getFilename();
            if (str_starts_with($name, '.')) {
                continue;
            }

            $relativePath = $prefix === '' ? $name : $prefix . '/' . $name;
            if ($item->isDir()) {
                $folders[] = [
                    'name' => $name,
                    'path' => $relativePath,
                    'object_count' => $this->countObjects($item->getPathname()),
                    'modified' => $item->getMTime(),
                ];
                continue;
            }

            $pathname = $item->getPathname();
            $files[] = [
                'name' => $name,
                'path' => $relativePath,
                'size' => $item->getSize(),
                'modified' => $item->getMTime(),
                'mime' => $this->detectMimeType($pathname),
                'is_image' => $this->isImage($pathname),
            ];
        }

        usort($folders, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));
        usort($files, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return ['folders' => $folders, 'files' => $files, 'bucket_root' => $bucketDir];
    }

    public function createBucket(string $name): void
    {
        $this->validateBucketName($name);
        $path = $this->bucketPath($name);
        if (is_dir($path)) {
            throw new RuntimeException('Bucket already exists: ' . $name);
        }

        if (!mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create bucket: ' . $name);
        }
    }

    public function createFolder(string $bucket, string $folderPath): void
    {
        $folderPath = $this->normalizeRelativePath($folderPath);
        if ($folderPath === '') {
            throw new RuntimeException('Folder name is required');
        }

        $fullPath = $this->resolveInsideBucket($bucket, $folderPath, false);
        if (file_exists($fullPath)) {
            throw new RuntimeException('Folder already exists');
        }

        if (!mkdir($fullPath, 0777, true) && !is_dir($fullPath)) {
            throw new RuntimeException('Failed to create folder');
        }
    }

    public function deleteObject(string $bucket, string $objectPath): void
    {
        $objectPath = $this->normalizeRelativePath($objectPath);
        if ($objectPath === '') {
            throw new RuntimeException('Item path is required');
        }

        $fullPath = $this->resolveInsideBucket($bucket, $objectPath, false);
        $bucketPath = $this->bucketPath($bucket);

        if (is_file($fullPath)) {
            if (!unlink($fullPath)) {
                throw new RuntimeException('Failed to delete file');
            }
            $this->cleanupEmptyParents(dirname($fullPath), $bucketPath);
            return;
        }

        if (is_dir($fullPath)) {
            $this->deleteDirectoryRecursive($fullPath);
            $this->cleanupEmptyParents(dirname($fullPath), $bucketPath);
            return;
        }

        throw new RuntimeException('Object not found');
    }

    public function deleteBucket(string $bucket): void
    {
        $path = $this->bucketPath($bucket);
        if (!is_dir($path)) {
            throw new RuntimeException('Bucket not found: ' . $bucket);
        }

        $this->deleteDirectoryRecursive($path);
    }

    public function rename(string $bucket, string $oldPath, string $newName): array
    {
        $oldPath = $this->normalizeRelativePath($oldPath);
        if ($oldPath === '') {
            throw new RuntimeException('Item path is required');
        }

        $this->validateSegmentName($newName);
        $oldFullPath = $this->resolveInsideBucket($bucket, $oldPath, false);
        if (!file_exists($oldFullPath)) {
            throw new RuntimeException('Object not found');
        }

        $newFullPath = dirname($oldFullPath) . '/' . $newName;
        if (file_exists($newFullPath)) {
            throw new RuntimeException('A file or folder with that name already exists');
        }

        if (!rename($oldFullPath, $newFullPath)) {
            throw new RuntimeException('Failed to rename');
        }

        $newPath = dirname($oldPath);
        $newPath = $newPath === '.' ? $newName : $newPath . '/' . $newName;

        return ['path' => $newPath, 'name' => $newName];
    }

    public function renameBucket(string $oldName, string $newName): void
    {
        $this->validateBucketName($newName);
        $oldPath = $this->bucketPath($oldName);
        $newPath = $this->bucketPath($newName);

        if (!is_dir($oldPath)) {
            throw new RuntimeException('Bucket not found: ' . $oldName);
        }
        if (file_exists($newPath)) {
            throw new RuntimeException('A bucket with that name already exists');
        }
        if (!rename($oldPath, $newPath)) {
            throw new RuntimeException('Failed to rename bucket');
        }
    }

    public function uploadFile(string $bucket, string $prefix, array $uploadedFile): string
    {
        $prefix = $this->normalizeRelativePath($prefix);

        if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload failed with error code: ' . (string) ($uploadedFile['error'] ?? 'unknown'));
        }

        $name = basename((string) ($uploadedFile['name'] ?? ''));
        $this->validateSegmentName($name);

        $targetDir = $this->resolveInsideBucket($bucket, $prefix, true);
        $targetPath = $targetDir . '/' . $name;
        if (!move_uploaded_file((string) $uploadedFile['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to move uploaded file');
        }

        return $prefix === '' ? $name : $prefix . '/' . $name;
    }

    public function objectInfo(string $bucket, string $objectPath): array
    {
        $objectPath = $this->normalizeRelativePath($objectPath);
        if ($objectPath === '') {
            throw new RuntimeException('File path is required');
        }

        $fullPath = $this->resolveInsideBucket($bucket, $objectPath, false);
        if (!is_file($fullPath)) {
            throw new RuntimeException('File not found');
        }

        return [
            'name' => basename($objectPath),
            'path' => $objectPath,
            'size' => filesize($fullPath) ?: 0,
            'modified' => filemtime($fullPath) ?: 0,
            'mime' => $this->detectMimeType($fullPath),
            'is_image' => $this->isImage($fullPath),
        ];
    }

    public function objectFullPath(string $bucket, string $objectPath): string
    {
        $fullPath = $this->resolveInsideBucket($bucket, $this->normalizeRelativePath($objectPath), false);
        if (!is_file($fullPath)) {
            throw new RuntimeException('File not found');
        }

        return $fullPath;
    }

    private function bucketPath(string $bucket): string
    {
        $this->validateBucketName($bucket);

        return rtrim($this->dataDir, '/') . '/' . $bucket;
    }

    private function validateBucketName(string $name): void
    {
        $this->validateSegmentName($name);
    }

    private function validateSegmentName(string $name): void
    {
        if ($name === '' || $name === '.' || $name === '..') {
            throw new RuntimeException('Invalid name');
        }
        if (str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, "\0")) {
            throw new RuntimeException('Name contains invalid characters');
        }
        if (str_starts_with($name, '.')) {
            throw new RuntimeException('Name cannot start with a dot');
        }
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        $clean = [];
        foreach ($segments as $segment) {
            $this->validateSegmentName($segment);
            $clean[] = $segment;
        }

        return implode('/', $clean);
    }

    private function resolveInsideBucket(string $bucket, string $relativePath, bool $allowMissing): string
    {
        $bucketPath = $this->bucketPath($bucket);
        if (!is_dir($bucketPath)) {
            throw new RuntimeException('Bucket not found: ' . $bucket);
        }

        if ($relativePath === '') {
            return $bucketPath;
        }

        $fullPath = $bucketPath . '/' . $relativePath;
        $parent = dirname($fullPath);
        if (!is_dir($parent)) {
            if ($allowMissing) {
                if (!mkdir($parent, 0777, true) && !is_dir($parent)) {
                    throw new RuntimeException('Failed to create target directory');
                }
            } else {
                throw new RuntimeException('Object not found');
            }
        }

        $resolvedParent = realpath($parent);
        $resolvedBucket = realpath($bucketPath);
        if ($resolvedParent === false || $resolvedBucket === false || !str_starts_with($resolvedParent, $resolvedBucket)) {
            throw new RuntimeException('Invalid path');
        }

        return $fullPath;
    }

    private function countObjects(string $directory): int
    {
        $count = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && !str_starts_with($file->getFilename(), '.')) {
                $count++;
            }
        }

        return $count;
    }

    private function detectMimeType(string $filePath): string
    {
        try {
            $mimeType = mime_content_type($filePath);
        } catch (\Throwable) {
            return 'application/octet-stream';
        }

        return $mimeType ?: 'application/octet-stream';
    }

    private function isImage(string $filePath): bool
    {
        return in_array($this->detectMimeType($filePath), ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'], true);
    }

    private function deleteDirectoryRecursive(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            unlink($path);
            return;
        }

        $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $this->deleteDirectoryRecursive($item->getPathname());
        }

        rmdir($path);
    }

    private function cleanupEmptyParents(string $startDir, string $stopAt): void
    {
        $current = $startDir;
        while ($current !== $stopAt && is_dir($current)) {
            $iterator = new FilesystemIterator($current, FilesystemIterator::SKIP_DOTS);
            if ($iterator->valid()) {
                break;
            }
            rmdir($current);
            $current = dirname($current);
        }
    }
}
