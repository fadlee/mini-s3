<?php

declare(strict_types=1);

namespace MiniS3\S3;

final class RequestValidator
{
    public function isValidBucketName(string $bucket): bool
    {
        $length = strlen($bucket);
        if ($length < 3 || $length > 63) {
            return false;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $bucket)) {
            return false;
        }

        if (str_contains($bucket, '..') || str_contains($bucket, '.-') || str_contains($bucket, '-.')) {
            return false;
        }

        if (filter_var($bucket, FILTER_VALIDATE_IP)) {
            return false;
        }

        return true;
    }

    public function isValidObjectKey(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        if (str_contains($key, "\0")) {
            return false;
        }

        foreach (explode('/', $key) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }

            if (in_array(strtolower($segment), ['.htaccess', '.htpasswd', '.user.ini'], true)) {
                return false;
            }
        }

        return true;
    }

    public function isValidUploadId(string $uploadId): bool
    {
        return preg_match('/^[a-f0-9]{32}$/D', $uploadId) === 1;
    }

    public function isPositiveInteger(string $value): bool
    {
        return ctype_digit($value) && (int) $value > 0;
    }

    public function isOversizedRequest(?string $contentLength, int $maxRequestSize): bool
    {
        if ($contentLength === null || $contentLength === '' || !ctype_digit($contentLength)) {
            return false;
        }

        return (int) $contentLength > $maxRequestSize;
    }

    public function objectEtag(int $mtime, int $size): string
    {
        // Not an MD5: the "-" keeps clients from verifying it as a content hash.
        return '"' . dechex($mtime) . '-' . dechex($size) . '"';
    }

    public function isNotModified(?string $ifNoneMatch, ?string $ifModifiedSince, string $etag, int $mtime): bool
    {
        if ($ifNoneMatch !== null && trim($ifNoneMatch) !== '') {
            foreach (explode(',', $ifNoneMatch) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '*' || $this->stripWeak($candidate) === $etag) {
                    return true;
                }
            }

            return false;
        }

        if ($ifModifiedSince !== null && trim($ifModifiedSince) !== '') {
            $since = strtotime($ifModifiedSince);

            return $since !== false && $mtime <= $since;
        }

        return false;
    }

    public function isRangeApplicable(?string $ifRange, string $etag, int $mtime): bool
    {
        if ($ifRange === null || trim($ifRange) === '') {
            return true;
        }

        $ifRange = trim($ifRange);
        if (str_starts_with($ifRange, '"') || str_starts_with($ifRange, 'W/')) {
            return $ifRange === $etag;
        }

        $date = strtotime($ifRange);

        return $date !== false && $date === $mtime;
    }

    private function stripWeak(string $etag): string
    {
        return str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
    }

    public function parseRange(string $range, int $fileSize): array
    {
        if (!preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches)) {
            return [false, 0, 0];
        }

        if ($fileSize <= 0) {
            return [false, 0, 0];
        }

        $startRaw = $matches[1];
        $endRaw = $matches[2];

        if ($startRaw === '' && $endRaw === '') {
            return [false, 0, 0];
        }

        if ($startRaw === '') {
            if (!ctype_digit($endRaw)) {
                return [false, 0, 0];
            }

            $suffixLength = (int) $endRaw;
            if ($suffixLength <= 0) {
                return [false, 0, 0];
            }

            return [true, max(0, $fileSize - $suffixLength), $fileSize - 1];
        }

        if (!ctype_digit($startRaw)) {
            return [false, 0, 0];
        }

        $start = (int) $startRaw;
        if ($start >= $fileSize) {
            return [false, 0, 0];
        }

        if ($endRaw === '') {
            return [true, $start, $fileSize - 1];
        }

        if (!ctype_digit($endRaw)) {
            return [false, 0, 0];
        }

        $end = min((int) $endRaw, $fileSize - 1);
        if ($start > $end) {
            return [false, 0, 0];
        }

        return [true, $start, $end];
    }
}
