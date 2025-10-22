<?php

namespace App\Services;

/**
 * ValidationService
 * 
 * Handles validation for bucket names, object keys, and access keys
 * Following AWS S3 naming rules
 */
class ValidationService
{
    /**
     * Validate bucket name according to S3 rules
     * 
     * Rules:
     * - Must be between 3 and 63 characters long
     * - Can contain lowercase letters, numbers, dots, and hyphens
     * - Must begin and end with a letter or number
     * - Must not contain consecutive dots or invalid patterns
     * - Must not be formatted as an IP address
     *
     * @param string $bucket
     * @return bool
     */
    public function validateBucketName(string $bucket): bool
    {
        $length = strlen($bucket);
        
        // Check length
        if ($length < 3 || $length > 63) {
            return false;
        }
        
        // Check format: lowercase alphanumeric with dots and hyphens
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $bucket)) {
            return false;
        }
        
        // Check for invalid patterns
        if (strpos($bucket, '..') !== false || 
            strpos($bucket, '.-') !== false || 
            strpos($bucket, '-.') !== false) {
            return false;
        }
        
        // Check if it's an IP address
        if (filter_var($bucket, FILTER_VALIDATE_IP)) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Validate object key
     * 
     * Rules:
     * - No null bytes
     * - No path traversal attempts (. or .. segments)
     *
     * @param string $key
     * @return bool
     */
    public function validateObjectKey(string $key): bool
    {
        // Empty keys are allowed for bucket operations
        if ($key === '') {
            return true;
        }
        
        // Check for null bytes
        if (strpos($key, "\0") !== false) {
            return false;
        }
        
        // Check for path traversal
        $segments = explode('/', $key);
        foreach ($segments as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate access key
     *
     * @param string|null $accessKey
     * @return bool
     */
    public function validateAccessKey(?string $accessKey): bool
    {
        if ($accessKey === null) {
            return false;
        }
        
        $allowedKeys = config('storage.allowed_access_keys', []);
        
        return in_array($accessKey, $allowedKeys, true);
    }
    
    /**
     * Extract access key ID from Authorization header or query parameter
     *
     * @param string|null $authorizationHeader
     * @param string|null $credentialParam
     * @return string|null
     */
    public function extractAccessKeyId(?string $authorizationHeader, ?string $credentialParam = null): ?string
    {
        // Try Authorization header first
        if ($authorizationHeader) {
            if (preg_match('/AWS4-HMAC-SHA256 Credential=([^\/]+)\//', $authorizationHeader, $matches)) {
                return $matches[1];
            }
        }
        
        // Try X-Amz-Credential query parameter
        if ($credentialParam) {
            $parts = explode('/', $credentialParam);
            if (count($parts) > 0 && !empty($parts[0])) {
                return $parts[0];
            }
        }
        
        return null;
    }
}
