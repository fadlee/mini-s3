<?php

namespace App\Controllers;

use Base;
use App\Services\StorageService;
use App\Services\XmlService;

class ObjectController extends BaseController
{
    private StorageService $storage;

    public function beforeRoute(Base $f3): void
    {
        parent::beforeRoute($f3);
        $this->storage = new StorageService($f3);
    }

    public function get(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];
        $key = $params['key'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        if (!$this->validator->validateObjectKey($key)) {
            XmlService::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }

        if (!$this->storage->objectExists($bucket, $key)) {
            XmlService::error('404', 'Object not found', "/{$bucket}/{$key}");
        }

        $filesize = $this->storage->getObjectSize($bucket, $key);
        $range = null;
        $rangeHeader = $f3->get('HEADERS.Range');

        if ($rangeHeader && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $matches)) {
            $start = $matches[1] === '' ? 0 : (int) $matches[1];
            $end = $matches[2] === '' ? $filesize - 1 : min((int) $matches[2], $filesize - 1);

            if ($start > $end || $start < 0) {
                header("Content-Range: bytes */{$filesize}");
                http_response_code(416);
                exit;
            }

            $range = [$start, $end];
        }

        $this->storage->streamObject($bucket, $key, $range);
    }

    public function put(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];
        $key = $params['key'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        if (!$this->validator->validateObjectKey($key)) {
            XmlService::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }

        $content = file_get_contents('php://input');
        $this->storage->saveObject($bucket, $key, $content);
        http_response_code(200);
    }

    public function head(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];
        $key = $params['key'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        if (!$this->validator->validateObjectKey($key)) {
            XmlService::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }

        if (!$this->storage->objectExists($bucket, $key)) {
            XmlService::error('404', 'Resource not found', "/{$bucket}/{$key}");
        }

        $size = $this->storage->getObjectSize($bucket, $key);
        $mimeType = $this->storage->getObjectMimeType($bucket, $key);

        header('Content-Length: ' . $size);
        header('Content-Type: ' . $mimeType);
        http_response_code(200);
    }

    public function delete(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];
        $key = $params['key'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        if (!$this->validator->validateObjectKey($key)) {
            XmlService::error('400', 'Invalid object key', "/{$bucket}/{$key}");
        }

        $this->storage->deleteObject($bucket, $key);
        http_response_code(204);
    }
}
