<?php

declare(strict_types=1);

namespace MiniS3\S3;

use SimpleXMLElement;

final class S3Response
{
    public function error(int $httpStatus, string $s3Code, string $message, string $resource = ''): never
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Error></Error>');
        $xml->addChild('Code', $s3Code);
        $xml->addChild('Message', $message);
        if ($resource !== '') {
            $xml->addChild('Resource', $resource);
        }

        $this->sendXml($xml, $httpStatus);
    }

    public function listObjects(array $files, string $bucket, string $prefix = ''): never
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult></ListBucketResult>');
        $xml->addChild('Name', $bucket);
        $xml->addChild('Prefix', $prefix);
        $xml->addChild('MaxKeys', '1000');
        $xml->addChild('IsTruncated', 'false');

        foreach ($files as $file) {
            $contents = $xml->addChild('Contents');
            $contents->addChild('Key', (string) $file['key']);
            $contents->addChild('LastModified', gmdate('Y-m-d\TH:i:s.000\Z', (int) $file['timestamp']));
            $contents->addChild('Size', (string) $file['size']);
            $contents->addChild('StorageClass', 'STANDARD');
        }

        $this->sendXml($xml, 200);
    }

    public function createMultipartUpload(string $bucket, string $key, string $uploadId): never
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult></InitiateMultipartUploadResult>');
        $xml->addChild('Bucket', $bucket);
        $xml->addChild('Key', $key);
        $xml->addChild('UploadId', $uploadId);

        $this->sendXml($xml, 200);
    }

    public function completeMultipartUpload(
        string $bucket,
        string $key,
        string $uploadId,
        string $host,
        string $scheme
    ): never {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult></CompleteMultipartUploadResult>');
        $xml->addChild('Location', sprintf('%s://%s/%s/%s', $scheme, $host, $bucket, $key));
        $xml->addChild('Bucket', $bucket);
        $xml->addChild('Key', $key);
        $xml->addChild('UploadId', $uploadId);

        $this->sendXml($xml, 200);
    }

    public function deleteResult(array $deletedKeys, array $errors): never
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><DeleteResult></DeleteResult>');

        foreach ($deletedKeys as $key) {
            $deleted = $xml->addChild('Deleted');
            $deleted->addChild('Key', $key);
        }

        foreach ($errors as $errorItem) {
            $error = $xml->addChild('Error');
            $error->addChild('Key', (string) ($errorItem['key'] ?? ''));
            $error->addChild('Code', (string) ($errorItem['code'] ?? 'InternalError'));
            $error->addChild('Message', (string) ($errorItem['message'] ?? 'Unknown error'));
        }

        $this->sendXml($xml, 200);
    }

    private function sendXml(SimpleXMLElement $xml, int $httpStatus): never
    {
        http_response_code($httpStatus);
        header('Content-Type: application/xml');
        echo $xml->asXML();
        exit;
    }
}
