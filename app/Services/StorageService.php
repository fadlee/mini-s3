<?php

namespace App\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use Base;

class StorageService
{
    private Base $f3;
    private string $dataDir;

    public function __construct(Base $f3)
    {
        $this->f3 = $f3;
        $this->dataDir = $f3->get('DATA_DIR');
    }

    public function listObjects(string $bucket, string $prefix = ''): array
    {
        $dir = "{$this->dataDir}/{$bucket}";
        $files = [];

        if (!file_exists($dir)) {
            return $files;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($iterator as $file) {
            if ($file->isDir() || strpos($file->getFilename(), '.') === 0) {
                continue;
            }

            $relativePath = substr($file->getPathname(), strlen($dir) + 1);

            if ($prefix && strpos($relativePath, $prefix) !== 0) {
                continue;
            }

            $files[] = [
                'key' => $relativePath,
                'size' => $file->getSize(),
                'timestamp' => $file->getMTime()
            ];
        }

        return $files;
    }

    public function objectExists(string $bucket, string $key): bool
    {
        return file_exists("{$this->dataDir}/{$bucket}/{$key}");
    }

    public function getObject(string $bucket, string $key): string
    {
        return file_get_contents("{$this->dataDir}/{$bucket}/{$key}");
    }

    public function getObjectSize(string $bucket, string $key): int
    {
        return filesize("{$this->dataDir}/{$bucket}/{$key}");
    }

    public function getObjectMimeType(string $bucket, string $key): string
    {
        return mime_content_type("{$this->dataDir}/{$bucket}/{$key}") ?: 'application/octet-stream';
    }

    public function saveObject(string $bucket, string $key, string $content): void
    {
        $filePath = "{$this->dataDir}/{$bucket}/{$key}";
        $dir = dirname($filePath);

        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($filePath, $content);
    }

    public function deleteObject(string $bucket, string $key): void
    {
        $filePath = "{$this->dataDir}/{$bucket}/{$key}";
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    public function deleteDirectory(string $path): void
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
            if ($item->isDir()) {
                $this->deleteDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    public function streamObject(string $bucket, string $key, ?array $range = null): void
    {
        $filePath = "{$this->dataDir}/{$bucket}/{$key}";
        $filesize = filesize($filePath);
        $mimeType = $this->getObjectMimeType($bucket, $key);
        $fp = fopen($filePath, 'rb');

        if ($fp === false) {
            XmlService::error('500', 'Failed to open file', "/{$bucket}/{$key}");
        }

        $start = 0;
        $end = $filesize - 1;
        $length = $filesize;

        if ($range) {
            [$start, $end] = $range;
            $length = $end - $start + 1;
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$filesize}");
            header("Content-Length: $length");
        } else {
            http_response_code(200);
            header("Content-Length: $filesize");
        }

        header('Accept-Ranges: bytes');
        header("Content-Type: $mimeType");
        header("Content-Disposition: attachment; filename=\"" . basename($key) . "\"");
        header("Cache-Control: private");
        header("Pragma: public");
        header('X-Powered-By: S3');

        fseek($fp, $start);

        $remaining = $length;
        $chunkSize = 8 * 1024 * 1024;
        while (!feof($fp) && $remaining > 0 && connection_aborted() == false) {
            $buffer = fread($fp, min($chunkSize, $remaining));
            echo $buffer;
            $remaining -= strlen($buffer);
            flush();
        }

        fclose($fp);
        exit;
    }
}
