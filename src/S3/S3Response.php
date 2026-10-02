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

    public function listObjects(array $page, string $bucket, array $options): never
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult></ListBucketResult>');
        $encoded = $options['encodingType'] === 'url';
        $add = static function (SimpleXMLElement $parent, string $name, string $value) use ($encoded): void {
            $parent->addChild($name, htmlspecialchars($encoded ? rawurlencode($value) : $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        };
        $add($xml, 'Name', $bucket);
        foreach (['prefix' => 'Prefix', 'delimiter' => 'Delimiter'] as $option => $element) {
            if ($options[$option] !== null) {
                $add($xml, $element, $options[$option]);
            }
        }
        if ($options['version'] === 1 && $options['marker'] !== null) {
            $add($xml, 'Marker', $options['marker']);
        }
        if ($options['version'] === 2 && $options['startAfter'] !== null) {
            $add($xml, 'StartAfter', $options['startAfter']);
        }
        if ($options['version'] === 2 && $options['continuationToken'] !== null) {
            $add($xml, 'ContinuationToken', $options['continuationToken']);
        }
        if ($encoded) {
            $add($xml, 'EncodingType', 'url');
        }
        $xml->addChild('MaxKeys', (string) $options['maxKeys']);
        $xml->addChild('IsTruncated', $page['truncated'] ? 'true' : 'false');
        if ($options['version'] === 2) {
            $xml->addChild('KeyCount', (string) count($page['entries']));
        }
        foreach ($page['entries'] as $entry) {
            if (isset($entry['prefix'])) {
                $common = $xml->addChild('CommonPrefixes');
                $add($common, 'Prefix', $entry['prefix']);
                continue;
            }
            $contents = $xml->addChild('Contents');
            $add($contents, 'Key', $entry['key']);
            $contents->addChild('LastModified', gmdate('Y-m-d\\TH:i:s.000\\Z', (int) $entry['timestamp']));
            $contents->addChild('Size', (string) $entry['size']);
            $contents->addChild('StorageClass', 'STANDARD');
        }
        if ($page['truncated'] && $page['last'] !== null) {
            if ($options['version'] === 1 && $options['delimiter'] !== null && $options['delimiter'] !== '') {
                $add($xml, 'NextMarker', $page['last']);
            } elseif ($options['version'] === 2) {
                $xml->addChild('NextContinuationToken', $options['nextToken']);
            }
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

    public function sendObjectHeaders(int $status, int $length, string $mimeType, string $filename, bool $attachment = true): void
    {
        http_response_code($status);
        header('Accept-Ranges: bytes');
        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . $length);
        if ($attachment) {
            header('Content-Disposition: attachment; filename="' . addcslashes($filename, "\\\"") . '"');
        }
        header('Cache-Control: private');
        header('Pragma: public');
    }

    public function sendRangeHeader(int $start, int $end, int $fileSize): void
    {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    }

    public function sendInvalidRangeHeader(int $fileSize): void
    {
        header('Content-Range: bytes */' . $fileSize);
    }

    private function sendXml(SimpleXMLElement $xml, int $httpStatus): never
    {
        http_response_code($httpStatus);
        header('Content-Type: application/xml');
        echo $xml->asXML();
        exit;
    }
}
