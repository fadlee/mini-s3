<?php

namespace App\Services;

use RuntimeException;

/**
 * MultipartService
 * 
 * Handles S3 multipart upload operations
 */
class MultipartService
{
    private string $dataDir;
    private StorageService $storage;
    
    public function __construct(StorageService $storage)
    {
        $this->dataDir = config('storage.data_dir');
        $this->storage = $storage;
    }
    
    /**
     * Get upload directory path for a multipart upload
     */
    private function getUploadDir(string $bucket, string $key, string $uploadId): string
    {
        return $this->dataDir . '/' . $bucket . '/' . $key . '-temp/' . $uploadId;
    }
    
    /**
     * Initiate multipart upload
     *
     * @param string $bucket
     * @param string $key
     * @return string Upload ID
     */
    public function initiateUpload(string $bucket, string $key): string
    {
        $uploadId = bin2hex(random_bytes(16));
        $uploadDir = $this->getUploadDir($bucket, $key, $uploadId);
        
        if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
            throw new RuntimeException("Failed to create upload directory");
        }
        
        return $uploadId;
    }
    
    /**
     * Upload a part
     *
     * @param string $bucket
     * @param string $key
     * @param string $uploadId
     * @param int $partNumber
     * @param mixed $content String or resource
     * @return string ETag of the uploaded part
     * @throws RuntimeException
     */
    public function uploadPart(
        string $bucket, 
        string $key, 
        string $uploadId, 
        int $partNumber, 
        $content
    ): string {
        $uploadDir = $this->getUploadDir($bucket, $key, $uploadId);
        
        if (!file_exists($uploadDir)) {
            throw new RuntimeException("Upload ID not found");
        }
        
        $partPath = $uploadDir . '/' . $partNumber;
        
        $result = file_put_contents($partPath, $content);
        
        if ($result === false) {
            throw new RuntimeException("Failed to save part");
        }
        
        return md5_file($partPath);
    }
    
    /**
     * Complete multipart upload
     *
     * @param string $bucket
     * @param string $key
     * @param string $uploadId
     * @param array $parts Array of ['PartNumber' => int, 'ETag' => string]
     * @return void
     * @throws RuntimeException
     */
    public function completeUpload(
        string $bucket, 
        string $key, 
        string $uploadId, 
        array $parts
    ): void {
        $uploadDir = $this->getUploadDir($bucket, $key, $uploadId);
        
        if (!file_exists($uploadDir)) {
            throw new RuntimeException("Upload ID not found");
        }
        
        // Sort parts by part number
        usort($parts, function($a, $b) {
            return $a['PartNumber'] <=> $b['PartNumber'];
        });
        
        // Merge parts into final file
        $filePath = $this->dataDir . '/' . $bucket . '/' . $key;
        $dir = dirname($filePath);
        
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        
        $fp = fopen($filePath, 'w');
        
        if ($fp === false) {
            throw new RuntimeException("Failed to open destination file");
        }
        
        foreach ($parts as $part) {
            $partNumber = $part['PartNumber'];
            $partPath = $uploadDir . '/' . $partNumber;
            
            if (!file_exists($partPath)) {
                fclose($fp);
                throw new RuntimeException("Part file missing: {$partNumber}");
            }
            
            $partContent = file_get_contents($partPath);
            
            if ($partContent === false) {
                fclose($fp);
                throw new RuntimeException("Failed to read part: {$partNumber}");
            }
            
            fwrite($fp, $partContent);
        }
        
        fclose($fp);
        
        // Clean up temp directory
        $this->storage->deleteDirectory($this->dataDir . '/' . $bucket . '/' . $key . '-temp');
    }
    
    /**
     * Abort multipart upload
     *
     * @param string $bucket
     * @param string $key
     * @param string $uploadId
     * @return bool True if aborted, false if upload didn't exist
     */
    public function abortUpload(string $bucket, string $key, string $uploadId): bool
    {
        $uploadDir = $this->getUploadDir($bucket, $key, $uploadId);
        
        if (!file_exists($uploadDir)) {
            return false;
        }
        
        $this->storage->deleteDirectory($uploadDir);
        
        // Try to remove parent temp directory if empty
        $tempDir = $this->dataDir . '/' . $bucket . '/' . $key . '-temp';
        if (file_exists($tempDir) && count(scandir($tempDir)) === 2) {
            @rmdir($tempDir);
        }
        
        return true;
    }
    
    /**
     * Check if upload exists
     *
     * @param string $bucket
     * @param string $key
     * @param string $uploadId
     * @return bool
     */
    public function uploadExists(string $bucket, string $key, string $uploadId): bool
    {
        $uploadDir = $this->getUploadDir($bucket, $key, $uploadId);
        return file_exists($uploadDir);
    }
}
