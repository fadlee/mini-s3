<?php

namespace App\Services;

/**
 * ValidationService
 * 
 * Handles S3 bucket and object key validation
 */
class ValidationService
{
    /**
     * Validate S3 bucket name
     * 
     * Rules:
     * - Length 3-63 characters
     * - Lowercase letters, numbers, dots, hyphens only
     * - Start and end with letter or number
     * - No consecutive dots or dot-hyphen combinations
     * - Cannot be formatted as IP address
     */
    public function validateBucketName(string $bucket): bool
    {
        $length = strlen($bucket);
        if ($length < 3 || $length > 63) {
            return false;
        }

        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $bucket)) {
            return false;
        }

        if (strpos($bucket, '..') !== false || 
            strpos($bucket, '.-') !== false || 
            strpos($bucket, '-.') !== false) {
            return false;
        }

        if (filter_var($bucket, FILTER_VALIDATE_IP)) {
            return false;
        }

        return true;
    }

    /**
     * Validate S3 object key
     * 
     * Rules:
     * - Cannot contain null bytes
     * - Path segments cannot be . or ..
     */
    public function validateObjectKey(string $key): bool
    {
        if ($key === '') {
            return true;
        }

        if (strpos($key, "\0") !== false) {
            return false;
        }

        $segments = explode('/', $key);
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
