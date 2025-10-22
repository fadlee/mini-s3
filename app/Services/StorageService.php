<?php

namespace App\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use RuntimeException;

/**
 * StorageService
 * 
 * Handles file system operations for object storage
 */
class StorageService
{
    private string $dataDir;
    
    public function __construct()
    {
        $this->dataDir = config('storage.data_dir');
        $this->ensureDataDirExists();
    }
    
    /**
     * Ensure data directory exists
     */
    private function ensureDataDirExists(): void
    {
        if (!file_exists($this->dataDir)) {
            mkdir($this->dataDir, 0777, true);
        }
    }
    
    /**
     * Get full file path for a bucket/key combination
     */
    private function getFilePath(string $bucket, string $key): string
    {
        return $this->dataDir . '/' . $bucket . '/' . $key;
    }
    
    /**
     * Save object to storage
     *
     * @param string $bucket
     * @param string $key
     * @param mixed $content String or resource
     * @return void
     * @throws RuntimeException
     */
    public function saveObject(string $bucket, string $key, $content): void
    {
        $filePath = $this->getFilePath($bucket, $key);
        $dir = dirname($filePath);
        
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        
        $result = file_put_contents($filePath, $content);
        
        if ($result === false) {
            throw new RuntimeException("Failed to save object: {$bucket}/{$key}");
        }
    }
    
    /**
     * Get object from storage
     *
     * @param string $bucket
     * @param string $key
     * @return string
     * @throws RuntimeException
     */
    public function getObject(string $bucket, string $key): string
    {
        $filePath = $this->getFilePath($bucket, $key);
        
        if (!file_exists($filePath)) {
            throw new RuntimeException("Object not found: {$bucket}/{$key}");
        }
        
        $content = file_get_contents($filePath);
        
        if ($content === false) {
            throw new RuntimeException("Failed to read object: {$bucket}/{$key}");
        }
        
        return $content;
    }
    
    /**
     * Get object as file pointer for streaming
     *
     * @param string $bucket
     * @param string $key
     * @return resource
     * @throws RuntimeException
     */
    public function getObjectStream(string $bucket, string $key)
    {
        $filePath = $this->getFilePath($bucket, $key);
        
        if (!file_exists($filePath)) {
            throw new RuntimeException("Object not found: {$bucket}/{$key}");
        }
        
        $fp = fopen($filePath, 'rb');
        
        if ($fp === false) {
            throw new RuntimeException("Failed to open object: {$bucket}/{$key}");
        }
        
        return $fp;
    }
    
    /**
     * Delete object from storage
     *
     * @param string $bucket
     * @param string $key
     * @return bool True if deleted, false if didn't exist
     */
    public function deleteObject(string $bucket, string $key): bool
    {
        $filePath = $this->getFilePath($bucket, $key);
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        return unlink($filePath);
    }
    
    /**
     * Check if object exists
     *
     * @param string $bucket
     * @param string $key
     * @return bool
     */
    public function objectExists(string $bucket, string $key): bool
    {
        $filePath = $this->getFilePath($bucket, $key);
        return file_exists($filePath);
    }
    
    /**
     * Get object size in bytes
     *
     * @param string $bucket
     * @param string $key
     * @return int
     * @throws RuntimeException
     */
    public function getObjectSize(string $bucket, string $key): int
    {
        $filePath = $this->getFilePath($bucket, $key);
        
        if (!file_exists($filePath)) {
            throw new RuntimeException("Object not found: {$bucket}/{$key}");
        }
        
        return filesize($filePath);
    }
    
    /**
     * Get object MIME type
     *
     * @param string $bucket
     * @param string $key
     * @return string
     * @throws RuntimeException
     */
    public function getObjectMimeType(string $bucket, string $key): string
    {
        $filePath = $this->getFilePath($bucket, $key);
        
        if (!file_exists($filePath)) {
            throw new RuntimeException("Object not found: {$bucket}/{$key}");
        }
        
        $mimeType = mime_content_type($filePath);
        return $mimeType ?: 'application/octet-stream';
    }
    
    /**
     * Get object ETag (MD5 hash)
     *
     * @param string $bucket
     * @param string $key
     * @return string
     * @throws RuntimeException
     */
    public function getObjectETag(string $bucket, string $key): string
    {
        $filePath = $this->getFilePath($bucket, $key);
        
        if (!file_exists($filePath)) {
            throw new RuntimeException("Object not found: {$bucket}/{$key}");
        }
        
        return md5_file($filePath);
    }
    
    /**
     * List objects in a bucket with optional prefix
     *
     * @param string $bucket
     * @param string $prefix
     * @return array Array of objects with 'key', 'size', 'timestamp'
     */
    public function listObjects(string $bucket, string $prefix = ''): array
    {
        $dir = $this->dataDir . '/' . $bucket;
        $files = [];
        
        if (!file_exists($dir)) {
            return $files;
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isDir() || strpos($file->getFilename(), '.') === 0) {
                continue;
            }
            
            $relativePath = substr($file->getPathname(), strlen($dir) + 1);
            
            // Filter by prefix if provided
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
    
    /**
     * Delete a directory recursively
     *
     * @param string $path
     * @return void
     */
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
}
