<?php

namespace App\Middleware;

use App\Services\ValidationService;

/**
 * AuthMiddleware
 * 
 * Authenticates requests using AWS signature validation
 */
class AuthMiddleware
{
    private ValidationService $validation;
    
    public function __construct()
    {
        $this->validation = new ValidationService();
    }
    
    /**
     * Handle incoming request
     *
     * @return callable Middleware function
     */
    public function __invoke(): callable
    {
        return function() {
            $accessKey = $this->extractAccessKey();
            
            if (!$this->validation->validateAccessKey($accessKey)) {
                response()->status(401)->exit();
            }
            
            // Continue to next middleware or controller
            return true;
        };
    }
    
    /**
     * Extract access key from Authorization header or query parameter
     *
     * @return string|null
     */
    private function extractAccessKey(): ?string
    {
        // Get Authorization header
        $authHeader = request()->headers('Authorization');
        
        // Get X-Amz-Credential query parameter
        $credential = request()->get('X-Amz-Credential');
        
        return $this->validation->extractAccessKeyId($authHeader, $credential);
    }
}
