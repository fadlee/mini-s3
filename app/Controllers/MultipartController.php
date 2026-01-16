<?php

namespace App\Controllers;

use Base;
use App\Services\StorageService;
use App\Services\MultipartService;
use App\Services\XmlService;
use SimpleXMLElement;

class MultipartController extends BaseController
{
    private StorageService $storage;
    private MultipartService $multipart;

    public function beforeRoute(Base $f3): void
    {
        parent::beforeRoute($f3);
        $this->storage = new StorageService($f3);
        $this->multipart = new MultipartService($f3, $this->storage);
    }

    public function uploadPart(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];
        $key = $params['key'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        if (!$this->validator->validateObjectKey($key)) {
            XmlService::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }

        $uploadId = $f3->get('GET.uploadId');
        $partNumber = (int) $f3->get('GET.partNumber');

        try {
            $content = file_get_contents('php://input');
            $etag = $this->multipart->uploadPart($bucket, $key, $uploadId, $partNumber, $content);
            
            header('ETag: ' . $etag);
            http_response_code(200);
        } catch (\RuntimeException $e) {
            XmlService::error('404', $e->getMessage(), "/{$bucket}/{$key}");
        }
    }

    public function handlePost(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];
        $key = $params['key'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        if (!$this->validator->validateObjectKey($key)) {
            XmlService::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }

        $uploads = $f3->get('GET.uploads');
        $uploadId = $f3->get('GET.uploadId');

        try {
            if ($uploads !== null) {
                $uploadId = $this->multipart->initiateUpload($bucket, $key);
                XmlService::initiateMultipartUpload($bucket, $key, $uploadId);
                
            } elseif ($uploadId) {
                $xmlBody = file_get_contents('php://input');
                $xml = simplexml_load_string($xmlBody);
                
                $parts = [];
                foreach ($xml->Part as $part) {
                    $parts[] = [
                        'PartNumber' => (int) $part->PartNumber,
                        'ETag' => (string) $part->ETag
                    ];
                }
                
                $this->multipart->completeUpload($bucket, $key, $uploadId, $parts);
                XmlService::completeMultipartUpload($bucket, $key, $uploadId);
                
            } else {
                XmlService::error('400', 'Invalid POST request: missing uploads or uploadId parameter', "/{$bucket}/{$key}");
            }
        } catch (\RuntimeException $e) {
            XmlService::error('404', $e->getMessage(), "/{$bucket}/{$key}");
        }
    }

    public function abortUpload(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];
        $key = $params['key'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        if (!$this->validator->validateObjectKey($key)) {
            XmlService::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }

        $uploadId = $f3->get('GET.uploadId');

        try {
            $this->multipart->abortUpload($bucket, $key, $uploadId);
            http_response_code(204);
        } catch (\RuntimeException $e) {
            XmlService::error('404', $e->getMessage(), "/{$bucket}/{$key}");
        }
    }
}
