<?php

declare(strict_types=1);

namespace MiniS3\S3;

use MiniS3\Auth\AuthException;
use MiniS3\Auth\SigV4Authenticator;
use MiniS3\Http\RequestContext;
use MiniS3\Storage\FileStorage;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class S3Router
{
    public function __construct(
        private readonly RequestContext $request,
        private readonly FileStorage $storage,
        private readonly S3Response $response,
        private readonly RequestValidator $validator,
        private readonly SigV4Authenticator $authenticator,
        private readonly int $maxRequestSize,
        private readonly bool $publicReadAllBuckets = false
    ) {
    }

    public function handle(): never
    {
        try {
            $this->sendCorsHeaders();

            $this->storage->ensureDataDirExists();

            [$bucket, $key] = $this->extractBucketAndKey();
            $method = $this->request->getMethod();

            if ($method === 'OPTIONS') {
                http_response_code(204);
                exit;
            }

            $this->validateBucketAndKey($method, $bucket, $key);
            $this->validateRequestSize();

            if (!$this->isPublicRead($method)) {
                $this->authenticator->authenticate($this->request);
            }

            switch ($method) {
                case 'PUT':
                    $this->handlePut($bucket, $key);
                    break;

                case 'POST':
                    $this->handlePost($bucket, $key);
                    break;

                case 'GET':
                    $this->handleGet($bucket, $key);
                    break;

                case 'HEAD':
                    $this->handleHead($bucket, $key);
                    break;

                case 'DELETE':
                    $this->handleDelete($bucket, $key);
                    break;

                default:
                    $this->response->error(405, 'MethodNotAllowed', 'Method not allowed', $this->resource($bucket, $key));
            }
        } catch (AuthException $e) {
            $this->response->error($e->getHttpStatus(), $e->getS3Code(), $e->getMessage());
        } catch (Throwable $e) {
            $this->response->error(500, 'InternalError', 'Internal server error');
        }
    }

    private function isPublicRead(string $method): bool
    {
        return $this->publicReadAllBuckets && ($method === 'GET' || $method === 'HEAD');
    }

    private function sendCorsHeaders(): void
    {
        $origin = $this->request->getHeader('origin');
        if ($origin === null || $origin === '') {
            return;
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: GET, HEAD, PUT, POST, DELETE, OPTIONS');
        header('Access-Control-Expose-Headers: ETag, Content-Length, Content-Range');

        $requestHeaders = $this->request->getHeader('access-control-request-headers');
        if ($requestHeaders !== null && $requestHeaders !== '') {
            header('Access-Control-Allow-Headers: ' . $requestHeaders);
        }

        header('Access-Control-Max-Age: 86400');
    }

    private function handlePut(string $bucket, string $key): never
    {
        $uploadId = $this->request->getQueryParam('uploadId');
        $partNumber = $this->request->getQueryParam('partNumber');

        if ($uploadId !== null && $partNumber !== null) {
            if (!$this->validator->isPositiveInteger($partNumber)) {
                $this->response->error(400, 'InvalidPart', 'partNumber must be a positive integer', $this->resource($bucket, $key));
            }

            if (!$this->storage->multipartDirExists($bucket, $key, $uploadId)) {
                $this->response->error(404, 'NoSuchUpload', 'Upload ID not found', $this->resource($bucket, $key));
            }

            $partPath = $this->storage->putMultipartPartFromInput($bucket, $key, $uploadId, (int) $partNumber);
            header('ETag: ' . md5_file($partPath));
            http_response_code(200);
            exit;
        }

        $this->storage->putObjectFromInput($bucket, $key);
        http_response_code(200);
        exit;
    }

    private function handlePost(string $bucket, string $key): never
    {
        if ($this->request->hasQueryParam('delete')) {
            $xml = $this->parseXmlBody();

            $quiet = isset($xml->Quiet) && strtolower((string) $xml->Quiet) === 'true';
            $deletedKeys = [];
            $errors = [];

            foreach ($xml->Object as $object) {
                $objectKey = (string) $object->Key;
                if (!$this->validator->isValidObjectKey($objectKey)) {
                    $errors[] = [
                        'key' => $objectKey,
                        'code' => 'InvalidObjectKey',
                        'message' => 'Invalid object key',
                    ];
                    continue;
                }

                $this->storage->deleteObject($bucket, $objectKey);
                if (!$quiet) {
                    $deletedKeys[] = $objectKey;
                }
            }

            $this->response->deleteResult($deletedKeys, $errors);
        }

        if ($this->request->hasQueryParam('uploads')) {
            $uploadId = bin2hex(random_bytes(16));
            $this->storage->createMultipartUpload($bucket, $key, $uploadId);
            $this->response->createMultipartUpload($bucket, $key, $uploadId);
        }

        $uploadId = $this->request->getQueryParam('uploadId');
        if ($uploadId !== null) {
            if (!$this->storage->multipartDirExists($bucket, $key, $uploadId)) {
                $this->response->error(404, 'NoSuchUpload', 'Upload ID not found', $this->resource($bucket, $key));
            }

            $xml = $this->parseXmlBody();
            $parts = [];
            foreach ($xml->Part as $part) {
                $partNumber = (string) $part->PartNumber;
                if (!$this->validator->isPositiveInteger($partNumber)) {
                    $this->response->error(400, 'InvalidPart', 'PartNumber must be a positive integer', $this->resource($bucket, $key));
                }

                $parts[] = (int) $partNumber;
            }

            if ($parts === []) {
                $this->response->error(400, 'InvalidPart', 'Multipart completion request has no parts', $this->resource($bucket, $key));
            }

            $parts = array_values(array_unique($parts));
            sort($parts, SORT_NUMERIC);

            try {
                $this->storage->completeMultipartUpload($bucket, $key, $uploadId, $parts);
                $this->storage->cleanupMultipartUpload($bucket, $key, $uploadId);
            } catch (RuntimeException $e) {
                if (str_starts_with($e->getMessage(), 'Part file missing')) {
                    $this->response->error(400, 'InvalidPart', $e->getMessage(), $this->resource($bucket, $key));
                }
                throw $e;
            }

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $this->response->completeMultipartUpload($bucket, $key, $uploadId, $this->request->getHost(), $scheme);
        }

        $this->response->error(
            400,
            'InvalidRequest',
            'Invalid POST request: missing delete, uploads or uploadId parameter',
            $this->resource($bucket, $key)
        );
    }

    private function handleGet(string $bucket, string $key): never
    {
        if ($key === '') {
            $prefix = (string) ($this->request->getQueryParam('prefix') ?? '');
            $files = $this->storage->listFiles($bucket, $prefix);
            $this->response->listObjects($files, $bucket, $prefix);
        }

        $metadata = $this->storage->objectMetadata($bucket, $key);
        if ($metadata === null) {
            $this->response->error(404, 'NoSuchKey', 'Object not found', $this->resource($bucket, $key));
        }

        $fileSize = (int) $metadata['size'];
        $fp = $this->storage->openObjectReadStream($bucket, $key);
        $mimeType = (string) $metadata['mimeType'];
        $range = $this->request->getHeader('range');

        $start = 0;
        $end = max(0, $fileSize - 1);
        $length = $fileSize;
        $status = 200;

        if ($range !== null && $range !== '') {
            [$isValidRange, $start, $end] = $this->validator->parseRange($range, $fileSize);
            if (!$isValidRange) {
                fclose($fp);
                $this->response->sendInvalidRangeHeader($fileSize);
                http_response_code(416);
                exit;
            }

            $status = 206;
            $length = ($fileSize === 0) ? 0 : ($end - $start + 1);
            $this->response->sendRangeHeader($start, $end, $fileSize);
        }

        $this->response->sendObjectHeaders($status, $length, $mimeType, basename($key));

        if ($length === 0) {
            fclose($fp);
            exit;
        }

        fseek($fp, $start);

        $remaining = $length;
        $chunkSize = 8 * 1024 * 1024;

        while (!feof($fp) && $remaining > 0 && (PHP_SAPI === 'cli' || connection_aborted() === 0)) {
            $buffer = fread($fp, min($chunkSize, $remaining));
            if ($buffer === false) {
                break;
            }

            echo $buffer;
            $remaining -= strlen($buffer);
            flush();
        }

        fclose($fp);
        exit;
    }

    private function handleHead(string $bucket, string $key): never
    {
        if ($key === '') {
            $this->response->error(400, 'InvalidRequest', 'Object key required for HEAD', $this->resource($bucket, $key));
        }

        $metadata = $this->storage->objectMetadata($bucket, $key);
        if ($metadata === null) {
            $this->response->error(404, 'NoSuchKey', 'Resource not found', $this->resource($bucket, $key));
        }

        $this->response->sendObjectHeaders(200, (int) $metadata['size'], (string) $metadata['mimeType'], basename($key), false);
        exit;
    }

    private function handleDelete(string $bucket, string $key): never
    {
        $uploadId = $this->request->getQueryParam('uploadId');
        if ($uploadId !== null) {
            if (!$this->storage->multipartDirExists($bucket, $key, $uploadId)) {
                $this->response->error(404, 'NoSuchUpload', 'Upload ID not found', $this->resource($bucket, $key));
            }

            $this->storage->abortMultipartUpload($bucket, $key, $uploadId);
            http_response_code(204);
            exit;
        }

        $this->storage->deleteObject($bucket, $key);
        http_response_code(204);
        exit;
    }

    private function validateBucketAndKey(string $method, string $bucket, string $key): void
    {
        if ($method !== 'GET' && $bucket === '') {
            $this->response->error(400, 'InvalidBucketName', 'Bucket name not specified', '/');
        }

        if ($method === 'GET' && $bucket === '') {
            $this->response->error(400, 'InvalidBucketName', 'Bucket name required', '/');
        }

        if ($bucket !== '' && !$this->validator->isValidBucketName($bucket)) {
            $this->response->error(400, 'InvalidBucketName', 'Invalid bucket name', '/' . $bucket);
        }

        if ($key !== '' && !$this->validator->isValidObjectKey($key)) {
            $this->response->error(400, 'InvalidObjectKey', 'Invalid object key', $this->resource($bucket, $key));
        }
    }

    private function validateRequestSize(): void
    {
        if ($this->validator->isOversizedRequest($this->request->getHeader('content-length'), $this->maxRequestSize)) {
            $this->response->error(413, 'EntityTooLarge', 'Request too large');
        }
    }

    private function extractBucketAndKey(): array
    {
        $trimmedPath = trim($this->request->getPath(), '/');
        if ($trimmedPath === '') {
            return ['', ''];
        }

        $rawParts = explode('/', $trimmedPath);
        $decodedParts = array_map('rawurldecode', $rawParts);

        $bucket = $decodedParts[0] ?? '';
        $key = implode('/', array_slice($decodedParts, 1));

        return [$bucket, $key];
    }

    private function parseXmlBody(): SimpleXMLElement
    {
        $inputStream = PHP_SAPI === 'cli' ? 'php://stdin' : 'php://input';
        $body = file_get_contents($inputStream);
        if (!is_string($body) || trim($body) === '') {
            $this->response->error(400, 'MalformedXML', 'The XML you provided was not well-formed or did not validate against our published schema.');
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $this->response->error(400, 'MalformedXML', 'The XML you provided was not well-formed or did not validate against our published schema.');
        }

        return $xml;
    }

    private function resource(string $bucket, string $key): string
    {
        if ($bucket === '') {
            return '/';
        }

        if ($key === '') {
            return '/' . $bucket;
        }

        return '/' . $bucket . '/' . $key;
    }
}
