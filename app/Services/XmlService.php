<?php

namespace App\Services;

use SimpleXMLElement;

class XmlService
{
    public static function error(string $code, string $message, string $resource = ''): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Error></Error>');
        $xml->addChild('Code', $code);
        $xml->addChild('Message', $message);
        $xml->addChild('Resource', $resource);

        header('Content-Type: application/xml');
        http_response_code((int) $code);
        echo $xml->asXML();
        exit;
    }

    public static function listObjects(array $files, string $bucket, string $prefix = ''): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult></ListBucketResult>');
        $xml->addChild('Name', $bucket);
        $xml->addChild('Prefix', $prefix);
        $xml->addChild('MaxKeys', '1000');
        $xml->addChild('IsTruncated', 'false');

        foreach ($files as $file) {
            $contents = $xml->addChild('Contents');
            $contents->addChild('Key', $file['key']);
            $contents->addChild('LastModified', date('Y-m-d\TH:i:s.000\Z', $file['timestamp']));
            $contents->addChild('Size', (string) $file['size']);
            $contents->addChild('StorageClass', 'STANDARD');
        }

        header('Content-Type: application/xml');
        echo $xml->asXML();
        exit;
    }

    public static function initiateMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult></InitiateMultipartUploadResult>');
        $xml->addChild('Bucket', $bucket);
        $xml->addChild('Key', $key);
        $xml->addChild('UploadId', $uploadId);

        header('Content-Type: application/xml');
        echo $xml->asXML();
        exit;
    }

    public static function completeMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult></CompleteMultipartUploadResult>');
        $xml->addChild('Location', "http://{$_SERVER['HTTP_HOST']}/{$bucket}/{$key}");
        $xml->addChild('Bucket', $bucket);
        $xml->addChild('Key', $key);
        $xml->addChild('UploadId', $uploadId);

        header('Content-Type: application/xml');
        echo $xml->asXML();
        exit;
    }

    public static function deleteResult(array $deleted, array $errors, bool $quiet): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><DeleteResult></DeleteResult>');

        if (!$quiet) {
            foreach ($deleted as $item) {
                $del = $xml->addChild('Deleted');
                $del->addChild('Key', $item['Key']);
            }
        }

        foreach ($errors as $error) {
            $err = $xml->addChild('Error');
            $err->addChild('Key', $error['Key']);
            $err->addChild('Code', $error['Code']);
            $err->addChild('Message', $error['Message']);
        }

        header('Content-Type: application/xml');
        echo $xml->asXML();
        exit;
    }
}
