<?php

namespace App\Services;

use Base;

class MultipartService
{
    private Base $f3;
    private StorageService $storage;

    public function __construct(Base $f3, StorageService $storage)
    {
        $this->f3 = $f3;
        $this->storage = $storage;
    }

    public function initiateUpload(string $bucket, string $key): string
    {
        $uploadId = bin2hex(random_bytes(16));
        $uploadDir = $this->f3->get('DATA_DIR') . "/{$bucket}/{$key}-temp/{$uploadId}";
        mkdir($uploadDir, 0777, true);
        return $uploadId;
    }

    public function uploadPart(string $bucket, string $key, string $uploadId, int $partNumber, string $content): string
    {
        $uploadDir = $this->f3->get('DATA_DIR') . "/{$bucket}/{$key}-temp/{$uploadId}";
        
        if (!file_exists($uploadDir)) {
            throw new \RuntimeException('Upload ID not found');
        }

        $partPath = "{$uploadDir}/{$partNumber}";
        file_put_contents($partPath, $content);
        
        return md5_file($partPath);
    }

    public function completeUpload(string $bucket, string $key, string $uploadId, array $parts): void
    {
        $uploadDir = $this->f3->get('DATA_DIR') . "/{$bucket}/{$key}-temp/{$uploadId}";
        
        if (!file_exists($uploadDir)) {
            throw new \RuntimeException('Upload ID not found');
        }

        $filePath = $this->f3->get('DATA_DIR') . "/{$bucket}/{$key}";
        $dir = dirname($filePath);
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        $fp = fopen($filePath, 'w');
        foreach ($parts as $part) {
            $partNumber = $part['PartNumber'];
            $partPath = "{$uploadDir}/{$partNumber}";
            
            if (!file_exists($partPath)) {
                fclose($fp);
                throw new \RuntimeException("Part file missing: {$partNumber}");
            }
            
            fwrite($fp, file_get_contents($partPath));
        }
        fclose($fp);

        $this->storage->deleteDirectory($this->f3->get('DATA_DIR') . "/{$bucket}/{$key}-temp");
    }

    public function abortUpload(string $bucket, string $key, string $uploadId): void
    {
        $uploadDir = $this->f3->get('DATA_DIR') . "/{$bucket}/{$key}-temp/{$uploadId}";
        
        if (!file_exists($uploadDir)) {
            throw new \RuntimeException('Upload ID not found');
        }

        $this->storage->deleteDirectory($uploadDir);
    }
}
