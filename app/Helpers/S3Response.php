<?php

namespace App\Helpers;

use SimpleXMLElement;

/**
 * S3Response
 * 
 * Helper class for generating S3-compatible XML responses
 */
class S3Response
{
    /**
     * Generate S3 error response
     *
     * @param string $code HTTP status code or error code
     * @param string $message Error message
     * @param string $resource Resource path
     * @return void
     */
    public static function error(string $code, string $message, string $resource = ''): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Error></Error>');
        $xml->addChild('Code', $code);
        $xml->addChild('Message', htmlspecialchars($message));
        $xml->addChild('Resource', htmlspecialchars($resource));
        
        response()
            ->status((int)$code)
            ->withHeader('Content-Type', 'application/xml')
            ->markup($xml->asXML())
        ;\n        exit;
    }
    
    /**
     * Generate list objects response
     *
     * @param array $files Array of files with 'key', 'size', 'timestamp'
     * @param string $bucket Bucket name
     * @param string $prefix Prefix filter
     * @return void
     */
    public static function listObjects(array $files, string $bucket, string $prefix = ''): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListBucketResult></ListBucketResult>');
        $xml->addChild('Name', htmlspecialchars($bucket));
        $xml->addChild('Prefix', htmlspecialchars($prefix));
        $xml->addChild('MaxKeys', '1000');
        $xml->addChild('IsTruncated', 'false');
        
        foreach ($files as $file) {
            $contents = $xml->addChild('Contents');
            $contents->addChild('Key', htmlspecialchars($file['key']));
            $contents->addChild('LastModified', date('Y-m-d\TH:i:s.000\Z', $file['timestamp']));
            $contents->addChild('Size', (string)$file['size']);
            $contents->addChild('StorageClass', 'STANDARD');
        }
        
        response()
            ->withHeader('Content-Type', 'application/xml')
            ->markup($xml->asXML())
        ;\n        exit;
    }
    
    /**
     * Generate initiate multipart upload response
     *
     * @param string $bucket Bucket name
     * @param string $key Object key
     * @param string $uploadId Upload ID
     * @return void
     */
    public static function initiateMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><InitiateMultipartUploadResult></InitiateMultipartUploadResult>');
        $xml->addChild('Bucket', htmlspecialchars($bucket));
        $xml->addChild('Key', htmlspecialchars($key));
        $xml->addChild('UploadId', htmlspecialchars($uploadId));
        
        response()
            ->withHeader('Content-Type', 'application/xml')
            ->markup($xml->asXML())
        ;\n        exit;
    }
    
    /**
     * Generate complete multipart upload response
     *
     * @param string $bucket Bucket name
     * @param string $key Object key
     * @param string $uploadId Upload ID
     * @return void
     */
    public static function completeMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUploadResult></CompleteMultipartUploadResult>');
        $xml->addChild('Location', htmlspecialchars("http://{$_SERVER['HTTP_HOST']}/{$bucket}/{$key}"));
        $xml->addChild('Bucket', htmlspecialchars($bucket));
        $xml->addChild('Key', htmlspecialchars($key));
        $xml->addChild('UploadId', htmlspecialchars($uploadId));
        
        response()
            ->withHeader('Content-Type', 'application/xml')
            ->markup($xml->asXML())
        ;\n        exit;
    }
    
    /**
     * Generate delete result response for multi-object delete
     *
     * @param array $deleted Array of deleted objects with 'Key'
     * @param array $errors Array of errors with 'Key', 'Code', 'Message'
     * @param bool $quiet Suppress success responses
     * @return void
     */
    public static function deleteResult(array $deleted, array $errors = [], bool $quiet = false): void
    {
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><DeleteResult></DeleteResult>');
        
        // Add deleted objects (unless quiet mode)
        if (!$quiet) {
            foreach ($deleted as $object) {
                $deletedNode = $xml->addChild('Deleted');
                $deletedNode->addChild('Key', htmlspecialchars($object['Key']));
            }
        }
        
        // Add errors
        foreach ($errors as $error) {
            $errorNode = $xml->addChild('Error');
            $errorNode->addChild('Key', htmlspecialchars($error['Key']));
            $errorNode->addChild('Code', htmlspecialchars($error['Code']));
            $errorNode->addChild('Message', htmlspecialchars($error['Message']));
        }
        
        response()
            ->status(200)
            ->withHeader('Content-Type', 'application/xml')
            ->markup($xml->asXML())
        ;\n        exit;
    }
    
    /**
     * Send object response with streaming support
     *
     * @param resource $filePointer File pointer
     * @param int $filesize Total file size
     * @param string $mimeType MIME type
     * @param string $filename Filename for Content-Disposition
     * @param array|null $range Range request [start, end] or null
     * @return void
     */
    public static function streamObject(
        $filePointer,
        int $filesize,
        string $mimeType,
        string $filename,
        ?array $range = null
    ): void {
        $start = 0;
        $end = $filesize - 1;
        $length = $filesize;
        
        if ($range !== null) {
            $start = $range[0];
            $end = $range[1];
            $length = $end - $start + 1;
            
            response()->status(206);
            response()->withHeader('Content-Range', "bytes {$start}-{$end}/{$filesize}");
            response()->withHeader('Content-Length', (string)$length);
        } else {
            response()->status(200);
            response()->withHeader('Content-Length', (string)$filesize);
        }
        
        response()->withHeader('Accept-Ranges', 'bytes');
        response()->withHeader('Content-Type', $mimeType);
        response()->withHeader('Content-Disposition', 'attachment; filename="' . basename($filename) . '"');
        response()->withHeader('Cache-Control', 'private');
        response()->withHeader('Pragma', 'public');
        response()->withHeader('X-Powered-By', 'S3');
        
        // Seek to start position
        fseek($filePointer, $start);
        
        $remaining = $length;
        $chunkSize = 8 * 1024 * 1024; // 8MB per chunk
        
        while (!feof($filePointer) && $remaining > 0 && connection_aborted() == false) {
            $buffer = fread($filePointer, min($chunkSize, $remaining));
            echo $buffer;
            $remaining -= strlen($buffer);
            flush();
        }
        
        fclose($filePointer);
        exit;
    }
}
