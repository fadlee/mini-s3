<?php

namespace App\Controllers;

use Base;
use App\Services\StorageService;
use App\Services\XmlService;
use SimpleXMLElement;

class BucketController extends BaseController
{
    private StorageService $storage;

    public function beforeRoute(Base $f3): void
    {
        parent::beforeRoute($f3);
        $this->storage = new StorageService($f3);
    }

    public function listObjects(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        $prefix = $f3->get('GET.prefix') ?? '';
        $files = $this->storage->listObjects($bucket, $prefix);
        XmlService::listObjects($files, $bucket, $prefix);
    }

    public function multiDelete(Base $f3, array $params): void
    {
        $bucket = $params['bucket'];

        if (!$this->validator->validateBucketName($bucket)) {
            XmlService::error('400', 'Invalid bucket name', "/{$bucket}");
        }

        $xmlBody = file_get_contents('php://input');
        $xml = simplexml_load_string($xmlBody);
        $quiet = isset($xml->Quiet) && (string) $xml->Quiet === 'true';

        $deleted = [];
        $errors = [];

        foreach ($xml->Object as $object) {
            $objectKey = (string) $object->Key;

            if (!$this->validator->validateObjectKey($objectKey)) {
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

        XmlService::deleteResult($deleted, $errors, $quiet);
    }
}
