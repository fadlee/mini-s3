<?php

declare(strict_types=1);

namespace MiniS3\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FileStorage
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function ensureDataDirExists(): void
    {
        if (is_dir($this->dataDir)) {
            return;
        }

        if (!mkdir($this->dataDir, 0777, true) && !is_dir($this->dataDir)) {
            throw new RuntimeException('Failed to create data directory');
        }
    }

    public function listFiles(string $bucket, string $prefix = ''): array
    {
        $dir = $this->dataDir . '/' . $bucket;
        if (!is_dir($dir)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if (str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($dir) + 1);
            if ($prefix !== '' && !str_starts_with($relativePath, $prefix)) {
                continue;
            }

            $files[] = [
                'key' => $relativePath,
                'size' => $file->getSize(),
                'timestamp' => $file->getMTime(),
            ];
        }

        return $files;
    }

    public function objectPath(string $bucket, string $key): string
    {
        return $this->dataDir . '/' . $bucket . '/' . $key;
    }

    public function objectExists(string $bucket, string $key): bool
    {
        return is_file($this->objectPath($bucket, $key));
    }

    public function putObjectFromInput(string $bucket, string $key): void
    {
        $filePath = $this->objectPath($bucket, $key);
        $this->ensureDirectory(dirname($filePath));
        $this->copyInputToFile($filePath);
    }

    public function deleteObject(string $bucket, string $key): void
    {
        $filePath = $this->objectPath($bucket, $key);
        if (is_file($filePath)) {
            unlink($filePath);
        }
    }

    public function createMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $uploadDir = $this->multipartDir($bucket, $key, $uploadId);
        $this->ensureDirectory($uploadDir);
    }

    public function multipartDir(string $bucket, string $key, string $uploadId): string
    {
        return $this->dataDir . '/' . $bucket . '/' . $key . '-temp/' . $uploadId;
    }

    public function multipartDirExists(string $bucket, string $key, string $uploadId): bool
    {
        return is_dir($this->multipartDir($bucket, $key, $uploadId));
    }

    public function putMultipartPartFromInput(string $bucket, string $key, string $uploadId, int $partNumber): string
    {
        $uploadDir = $this->multipartDir($bucket, $key, $uploadId);
        if (!is_dir($uploadDir)) {
            throw new RuntimeException('Upload ID not found');
        }

        $partPath = $uploadDir . '/' . $partNumber;
        $this->copyInputToFile($partPath);

        return $partPath;
    }

    public function completeMultipartUpload(string $bucket, string $key, string $uploadId, array $partNumbers): void
    {
        $uploadDir = $this->multipartDir($bucket, $key, $uploadId);
        if (!is_dir($uploadDir)) {
            throw new RuntimeException('Upload ID not found');
        }

        $filePath = $this->objectPath($bucket, $key);
        $this->ensureDirectory(dirname($filePath));

        $out = fopen($filePath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Failed to open destination file');
        }

        foreach ($partNumbers as $partNumber) {
            $partPath = $uploadDir . '/' . $partNumber;
            if (!is_file($partPath)) {
                fclose($out);
                throw new RuntimeException('Part file missing: ' . $partNumber);
            }

            $in = fopen($partPath, 'rb');
            if ($in === false) {
                fclose($out);
                throw new RuntimeException('Failed to open multipart part: ' . $partNumber);
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
        }

        fclose($out);
    }

    public function cleanupMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $uploadDir = $this->multipartDir($bucket, $key, $uploadId);
        $this->deleteDirectory($uploadDir);

        $tempRoot = $this->dataDir . '/' . $bucket . '/' . $key . '-temp';
        if (is_dir($tempRoot) && $this->isDirectoryEmpty($tempRoot)) {
            rmdir($tempRoot);
        }
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->cleanupMultipartUpload($bucket, $key, $uploadId);
    }

    private function copyInputToFile(string $targetPath): void
    {
        $inputStream = PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input';
        $input = fopen($inputStream, 'rb');
        if ($input === false) {
            throw new RuntimeException('Failed to read request body');
        }

        $output = fopen($targetPath, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Failed to write file');
        }

        stream_copy_to_stream($input, $output);

        fclose($output);
        fclose($input);
    }

    private function ensureDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create directory: ' . $dir);
        }
    }

    private function deleteDirectory(string $path): void
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
            $this->deleteDirectory($item->getPathname());
        }

        rmdir($path);
    }

    private function isDirectoryEmpty(string $dir): bool
    {
        $iterator = new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);

        return !$iterator->valid();
    }
}
