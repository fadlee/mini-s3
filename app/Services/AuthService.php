<?php

namespace App\Services;

use Base;

class AuthService
{
    private Base $f3;
    
    public function __construct(Base $f3)
    {
        $this->f3 = $f3;
    }

    public function extractAccessKeyId(): ?string
    {
        $authorization = $this->f3->get('HEADERS.Authorization') ?? '';
        if (preg_match('/AWS4-HMAC-SHA256 Credential=([^\/]+)\//', $authorization, $matches)) {
            return $matches[1];
        }

        $credential = $this->f3->get('GET.X-Amz-Credential') ?? '';
        if ($credential) {
            $parts = explode('/', $credential);
            if (count($parts) > 0 && !empty($parts[0])) {
                return $parts[0];
            }
        }

        return null;
    }

    public function checkAuth(): void
    {
        $accessKeyId = $this->extractAccessKeyId();
        $allowedKeys = $this->f3->get('ALLOWED_ACCESS_KEYS');
        
        if (!$accessKeyId || !in_array($accessKeyId, $allowedKeys)) {
            http_response_code(401);
            exit;
        }
    }
}
