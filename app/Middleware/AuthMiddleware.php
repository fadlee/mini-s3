<?php

namespace App\Middleware;

use App\Services\ValidationService;
use Leaf\Middleware;

/**
 * AuthMiddleware
 * 
 * Authenticates requests using AWS signature validation
 */
class AuthMiddleware extends Middleware
{
    private ValidationService $validation;
    
    public function __construct()
    {
        $this->validation = new ValidationService();
    }
    
    /**
     * Handle incoming request
     */
    public function call()
    {
        $accessKey = $this->extractAccessKey();
        
        if (!$this->validation->validateAccessKey($accessKey)) {
            response()->status(401)->exit();
        }
        
        // Continue to next middleware or controller
        $this->next();
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
