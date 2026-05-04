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
        }

        return true;
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
