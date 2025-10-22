<?php

namespace App\Controllers;

use App\Services\StorageService;
use App\Services\ValidationService;
use App\Services\MultipartService;
use App\Helpers\S3Response;
use RuntimeException;

/**
 * S3Controller
 * 
 * Handles all S3 API operations
 */
class S3Controller
{
    private StorageService $storage;
    private ValidationService $validation;
    private MultipartService $multipart;
    
    public function __construct()
    {
        $this->storage = new StorageService();
        $this->validation = new ValidationService();
        $this->multipart = new MultipartService($this->storage);
    }
    
    /**
     * Check authentication
     */
    private function checkAuth(): void
    {
        $authHeader = request()->headers('Authorization');
        $credential = request()->get('X-Amz-Credential');
        $accessKey = $this->validation->extractAccessKeyId($authHeader, $credential);
        
        if (!$this->validation->validateAccessKey($accessKey)) {
            response()->status(401);
            exit;
        }
    }
    
    /**
     * PUT /{bucket}/{key} - Upload object or part
     */
    public function putObject($bucket = null, $key = null)
    {
        $this->checkAuth();
        
        // Validate
        if (!$this->validation->validateBucketName($bucket)) {
            S3Response::error('400', 'Invalid bucket name', "/{$bucket}");
        }
        
        if (!$this->validation->validateObjectKey($key)) {
            S3Response::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }
        
        $uploadId = request()->get('uploadId');
        $partNumber = request()->get('partNumber');
        
        try {
            if ($uploadId && $partNumber) {
                // Upload part
                $etag = $this->multipart->uploadPart(
                    $bucket,
                    $key,
                    $uploadId,
                    (int) $partNumber,
                    request()->body()
                );
                
                response()
                    ->withHeader('ETag', $etag)
                    ->plain('', 200);
            } else {
                // Upload single object
                $this->storage->saveObject($bucket, $key, request()->body());
                response()->plain('', 200);
            }
        } catch (RuntimeException $e) {
            S3Response::error('404', $e->getMessage(), "/{$bucket}/{$key}");
        }
    }
    
    /**
     * GET /{bucket}/{key} - Download object
     */
    public function getObject($bucket = null, $key = null)
    {
        $this->checkAuth();
        
        // Validate
        if (!$this->validation->validateBucketName($bucket)) {
            S3Response::error('400', 'Invalid bucket name', "/{$bucket}");
        }
        
        if (!$this->validation->validateObjectKey($key)) {
            S3Response::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }
        
        try {
            if (!$this->storage->objectExists($bucket, $key)) {
                S3Response::error('404', 'Object not found', "/{$bucket}/{$key}");
            }
            
            $filePointer = $this->storage->getObjectStream($bucket, $key);
            $filesize = $this->storage->getObjectSize($bucket, $key);
            $mimeType = $this->storage->getObjectMimeType($bucket, $key);
            
            // Parse range header
            $range = null;
            $rangeHeader = request()->headers('Range');
            
            if ($rangeHeader && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches)) {
                $start = $matches[1] === '' ? 0 : (int) $matches[1];
                $end = $matches[2] === '' ? $filesize - 1 : min((int) $matches[2], $filesize - 1);
                
                if ($start > $end || $start < 0) {
                    response()
                        ->withHeader('Content-Range', "bytes */{$filesize}")
                        ->status(416)
                    ;
                exit;
                }
                
                $range = [$start, $end];
            }
            
            S3Response::streamObject($filePointer, $filesize, $mimeType, $key, $range);
            
        } catch (RuntimeException $e) {
            S3Response::error('500', $e->getMessage(), "/{$bucket}/{$key}");
        }
    }
    
    /**
     * GET /{bucket}/ - List objects
     */
    public function listObjects($bucket = null)
    {
        $this->checkAuth();
        
        // Validate
        if (!$this->validation->validateBucketName($bucket)) {
            S3Response::error('400', 'Invalid bucket name', "/{$bucket}");
        }
        
        $prefix = request()->get('prefix', '');
        $files = $this->storage->listObjects($bucket, $prefix);
        
        S3Response::listObjects($files, $bucket, $prefix);
    }
    
    /**
     * HEAD /{bucket}/{key} - Get object metadata
     */
    public function headObject($bucket = null, $key = null)
    {
        $this->checkAuth();
        
        // Validate
        if (!$this->validation->validateBucketName($bucket)) {
            S3Response::error('400', 'Invalid bucket name', "/{$bucket}");
        }
        
        if (!$this->validation->validateObjectKey($key)) {
            S3Response::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }
        
        try {
            if (!$this->storage->objectExists($bucket, $key)) {
                S3Response::error('404', 'Resource not found', "/{$bucket}/{$key}");
            }
            
            $size = $this->storage->getObjectSize($bucket, $key);
            $mimeType = $this->storage->getObjectMimeType($bucket, $key);
            
            response()
                ->withHeader('Content-Length', (string) $size)
                ->withHeader('Content-Type', $mimeType)
                ->plain('', 200);
                
        } catch (RuntimeException $e) {
            S3Response::error('500', $e->getMessage(), "/{$bucket}/{$key}");
        }
    }
    
    /**
     * DELETE /{bucket}/{key} - Delete object or abort upload
     */
    public function deleteObject($bucket = null, $key = null)
    {
        error_log("DELETE: bucket=$bucket, key=$key");
        $this->checkAuth();
        
        // Validate
        if (!$this->validation->validateBucketName($bucket)) {
            S3Response::error('400', 'Invalid bucket name', "/{$bucket}");
        }
        
        if (!$this->validation->validateObjectKey($key)) {
            S3Response::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }
        
        $uploadId = request()->get('uploadId');
        
        if ($uploadId) {
            // Abort multipart upload
            error_log("DELETE: Aborting multipart upload=$uploadId");
            $this->multipart->abortUpload($bucket, $key, $uploadId);
        } else {
            // Delete object
            error_log("DELETE: Deleting object");
            $result = $this->storage->deleteObject($bucket, $key);
            error_log("DELETE: Result=$result");
        }
        
        error_log("DELETE: Sending 204 response");
        response()->noContent();
    }
    
    /**
     * POST /{bucket}/{key} - Multipart operations
     */
    public function postObject($bucket = null, $key = null)
    {
        $this->checkAuth();
        
        // Validate
        if (!$this->validation->validateBucketName($bucket)) {
            S3Response::error('400', 'Invalid bucket name', "/{$bucket}");
        }
        
        if (!$this->validation->validateObjectKey($key)) {
            S3Response::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }
        
        $uploads = request()->get('uploads');
        $uploadId = request()->get('uploadId');
        
        try {
            if ($uploads !== null) {
                // Initiate multipart upload
                $uploadId = $this->multipart->initiateUpload($bucket, $key);
                S3Response::initiateMultipartUpload($bucket, $key, $uploadId);
                
            } elseif ($uploadId) {
                // Complete multipart upload
                $xmlBody = request()->body();
                $xml = simplexml_load_string($xmlBody);
                
                $parts = [];
                foreach ($xml->Part as $part) {
                    $parts[] = [
                        'PartNumber' => (int) $part->PartNumber,
                        'ETag' => (string) $part->ETag
                    ];
                }
                
                $this->multipart->completeUpload($bucket, $key, $uploadId, $parts);
                S3Response::completeMultipartUpload($bucket, $key, $uploadId);
                
            } else {
                S3Response::error('400', 'Invalid POST request: missing uploads or uploadId parameter', "/{$bucket}/{$key}");
            }
        } catch (RuntimeException $e) {
            S3Response::error('404', $e->getMessage(), "/{$bucket}/{$key}");
        }
    }
    
    /**
     * POST /{bucket}/ - Multi-object delete
     */
    public function postBucket($bucket = null)
    {
        $this->checkAuth();
        
        // Validate
        if (!$this->validation->validateBucketName($bucket)) {
            S3Response::error('400', 'Invalid bucket name', "/{$bucket}");
        }
        
        $delete = request()->get('delete');
        
        if ($delete === null) {
            S3Response::error('400', 'Invalid POST request: missing delete parameter', "/{$bucket}");
        }
        
        try {
            $xmlBody = request()->body();
            $xml = simplexml_load_string($xmlBody);
            $quiet = isset($xml->Quiet) && (string) $xml->Quiet === 'true';
            
            $deleted = [];
            $errors = [];
            
            foreach ($xml->Object as $object) {
                $objectKey = (string) $object->Key;
                
                if (!$this->validation->validateObjectKey($objectKey)) {
                    $errors[] = [
                        'Key' => $objectKey,
                        'Code' => 'InvalidObjectKey',
                        'Message' => 'Invalid object key'
                    ];
                    continue;
                }
                
                $this->storage->deleteObject($bucket, $objectKey);
                $deleted[] = ['Key' => $objectKey];
            }
            
            S3Response::deleteResult($deleted, $errors, $quiet);
            
        } catch (\Exception $e) {
            S3Response::error('500', 'Internal Server Error', "/{$bucket}");
        }
    }
}
