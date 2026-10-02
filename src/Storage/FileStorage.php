<?php

declare(strict_types=1);

namespace MiniS3\Storage;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class FileStorage
{
    private const MULTIPART_ROOT = '.multipart';

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
    public function listPage(string $bucket, string $prefix, string $delimiter, int $maxKeys, ?string $after): array
    {
        $files = $this->listFiles($bucket, $prefix);
        usort($files, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));
        $items = [];
        $seen = [];
        foreach ($files as $file) {
            $key = $file['key'];
            $relative = substr($key, strlen($prefix));
            $separator = $delimiter === '' ? false : strpos($relative, $delimiter);
            if ($separator !== false) {
                $name = $prefix . substr($relative, 0, $separator + strlen($delimiter));
                if (isset($seen[$name]) || ($after !== null && strcmp($name, $after) <= 0)) {
                    continue;
                }
                $seen[$name] = true;
                $items[] = ['prefix' => $name];
            } elseif ($after === null || strcmp($key, $after) > 0) {
                $items[] = $file;
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp($a['key'] ?? $a['prefix'], $b['key'] ?? $b['prefix']));
        $truncated = count($items) > $maxKeys;
        $entries = array_slice($items, 0, $maxKeys);
        $lastEntry = $entries === [] ? null : $entries[count($entries) - 1];
        return ['entries' => $entries, 'truncated' => $truncated, 'last' => $lastEntry['key'] ?? $lastEntry['prefix'] ?? null];
    }


    public function objectPath(string $bucket, string $key): string
    {
        return $this->dataDir . '/' . $bucket . '/' . $key;
    }

    public function objectExists(string $bucket, string $key): bool
    {
        return is_file($this->objectPath($bucket, $key));
    }

    public function objectMetadata(string $bucket, string $key): ?array
    {
        $filePath = $this->objectPath($bucket, $key);
        if (!is_file($filePath)) {
            return null;
        }

        $size = filesize($filePath);
        if ($size === false) {
            throw new RuntimeException('Failed to read file metadata');
        }

        return [
            'size' => $size,
            'mimeType' => $this->detectMimeType($filePath),
        ];
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

    public function openObjectReadStream(string $bucket, string $key)
    {
        $stream = fopen($this->objectPath($bucket, $key), 'rb');
        if ($stream === false) {
            throw new RuntimeException('Failed to open file');
        }

        return $stream;
    }

    public function putObjectFromInput(string $bucket, string $key, int $maxBytes): void
    {
        $filePath = $this->objectPath($bucket, $key);
        $this->ensureDirectory(dirname($filePath));
        $this->copyInputToAtomicFile($filePath, $maxBytes);
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
        return $this->dataDir
            . '/' . self::MULTIPART_ROOT
            . '/' . $bucket
            . '/' . $this->keyNamespace($key)
            . '/' . $uploadId;
    }

    public function multipartDirExists(string $bucket, string $key, string $uploadId): bool
    {
        return is_dir($this->multipartDir($bucket, $key, $uploadId));
    }

    public function putMultipartPartFromInput(string $bucket, string $key, string $uploadId, int $partNumber, int $maxBytes): string
    {
        $uploadDir = $this->multipartDir($bucket, $key, $uploadId);
        if (!is_dir($uploadDir)) {
            throw new RuntimeException('Upload ID not found');
        }

        $partPath = $uploadDir . '/' . $partNumber;
        $this->copyInputToAtomicFile($partPath, $maxBytes);

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

        $tmpPath = $this->createTempPath(dirname($filePath), '.obj-');
        $out = fopen($tmpPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Failed to open destination file');
        }

        try {
            foreach ($partNumbers as $partNumber) {
                $partPath = $uploadDir . '/' . $partNumber;
                if (!is_file($partPath)) {
                    throw new RuntimeException('Part file missing: ' . $partNumber);
                }

                $in = fopen($partPath, 'rb');
                if ($in === false) {
                    throw new RuntimeException('Failed to open multipart part: ' . $partNumber);
                }

                $copied = stream_copy_to_stream($in, $out);
                fclose($in);
                if ($copied === false) {
                    throw new RuntimeException('Failed to merge multipart part: ' . $partNumber);
                }
            }
        } catch (RuntimeException $e) {
            fclose($out);
            @unlink($tmpPath);
            throw $e;
        }

        fclose($out);

        if (!rename($tmpPath, $filePath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to finalize destination file');
        }
    }

    public function cleanupMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $uploadDir = $this->multipartDir($bucket, $key, $uploadId);
        $this->deleteDirectory($uploadDir);

        $keyRoot = dirname($uploadDir);
        $bucketRoot = dirname($keyRoot);
        $multipartRoot = $this->dataDir . '/' . self::MULTIPART_ROOT;

        $this->removeIfEmptyDirectory($keyRoot);
        $this->removeIfEmptyDirectory($bucketRoot);
        $this->removeIfEmptyDirectory($multipartRoot);
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->cleanupMultipartUpload($bucket, $key, $uploadId);
    }

    private function copyInputToAtomicFile(string $targetPath, int $maxBytes): void
    {
        $inputStream = PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input';
        $input = fopen($inputStream, 'rb');
        if ($input === false) {
            throw new RuntimeException('Failed to read request body');
        }

        $tmpPath = $this->createTempPath(dirname($targetPath), '.upload-');
        $output = fopen($tmpPath, 'wb');
        if ($output === false) {
            fclose($input);
            throw new RuntimeException('Failed to write file');
        }

        $totalBytes = 0;
        $chunkSize = 8 * 1024 * 1024;

        try {
            while (!feof($input)) {
                $buffer = fread($input, $chunkSize);
                if ($buffer === false) {
                    throw new RuntimeException('Failed to read request body');
                }
                if ($buffer === '') {
                    continue;
                }

                $totalBytes += strlen($buffer);
                if ($totalBytes > $maxBytes) {
                    throw new RuntimeException('Request body exceeds maximum size');
                }

                $written = fwrite($output, $buffer);
                if ($written === false || $written !== strlen($buffer)) {
                    throw new RuntimeException('Failed to write file');
                }
            }
        } catch (RuntimeException $e) {
            fclose($output);
            fclose($input);
            @unlink($tmpPath);
            throw $e;
        }

        fclose($output);
        fclose($input);

        if (!rename($tmpPath, $targetPath)) {
            @unlink($tmpPath);
            throw new RuntimeException('Failed to finalize file');
        }
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

    private function keyNamespace(string $key): string
    {
        if ($key === '') {
            return '_root';
        }

        return hash('sha256', $key);
    }

    private function createTempPath(string $directory, string $prefix): string
    {
        $tmpPath = tempnam($directory, $prefix);
        if ($tmpPath === false) {
            throw new RuntimeException('Failed to create temporary file');
        }

        return $tmpPath;
    }

    private function removeIfEmptyDirectory(string $dir): void
    {
        if (is_dir($dir) && $this->isDirectoryEmpty($dir)) {
            @rmdir($dir);
        }
    }
}
