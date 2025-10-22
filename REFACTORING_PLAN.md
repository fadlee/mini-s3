# Mini-S3 Leaf PHP Framework Refactoring Plan

## Current State Analysis

**Current Implementation:**
- Single `index.php` file (~480 lines)
- Procedural code with direct `$_SERVER` access
- Manual routing with switch/case statements
- No dependency injection or service containers
- No middleware pattern
- Direct file system operations
- Mixed concerns (routing, business logic, response handling)

**Strengths to Preserve:**
- ✅ Lightweight and fast
- ✅ Simple deployment (single file)
- ✅ Clear S3 API compatibility
- ✅ No database dependency

## Goals of Refactoring

1. **Better Code Organization**: Separate concerns using MVC pattern
2. **Improved Maintainability**: Easier to add features and fix bugs
3. **Testability**: Enable unit and integration testing
4. **Middleware Support**: Add logging, rate limiting, CORS easily
5. **Keep It Simple**: Maintain lightweight nature and ease of deployment
6. **Backward Compatibility**: Same S3 API endpoints and behavior

## Proposed Architecture

### Project Structure

```
mini-s3/
├── public/
│   ├── index.php              # Entry point
│   └── .htaccess              # Apache rewrite rules
├── app/
│   ├── Controllers/
│   │   └── S3Controller.php   # Main S3 operations
│   ├── Middleware/
│   │   ├── AuthMiddleware.php # AWS signature validation
│   │   └── CorsMiddleware.php # CORS headers
│   ├── Services/
│   │   ├── StorageService.php # File system operations
│   │   ├── ValidationService.php # Bucket/key validation
│   │   └── MultipartService.php # Multipart upload handling
│   ├── Helpers/
│   │   ├── S3Response.php     # S3 XML response helpers
│   │   └── PathHelper.php     # Path parsing utilities
│   └── routes.php             # Route definitions
├── config/
│   ├── app.php                # Application config
│   └── storage.php            # Storage configuration
├── data/                      # Storage directory
│   └── .htaccess
├── tests/
│   ├── Unit/
│   │   ├── ValidationServiceTest.php
│   │   └── PathHelperTest.php
│   └── Integration/
│       └── S3ControllerTest.php
├── vendor/                    # Composer dependencies
├── .env.example               # Environment template
├── .env                       # Environment config (gitignored)
├── composer.json
├── phpunit.xml
└── README.md
```

## Phase 1: Setup & Dependencies (Day 1)

### Step 1.1: Install Leaf PHP
```bash
composer init
composer require leafs/leaf
composer require leafs/router
composer require leafs/http
composer require leafs/exception
composer require vlucas/phpdotenv
composer require --dev phpunit/phpunit
composer require --dev phpstan/phpstan
```

### Step 1.2: Create Basic Structure
- Create directory structure
- Move current `index.php` to `legacy/index.php` for reference
- Create new entry point `public/index.php`

### Step 1.3: Setup Configuration
- Create `.env` file for configuration
- Create config files in `config/` directory
- Setup autoloading in `composer.json`

## Phase 2: Extract Business Logic (Day 2-3)

### Step 2.1: Create Service Classes

**StorageService.php** - File system operations
```php
class StorageService {
    public function saveObject(string $bucket, string $key, $content): void
    public function getObject(string $bucket, string $key): string
    public function deleteObject(string $bucket, string $key): void
    public function listObjects(string $bucket, string $prefix = ''): array
    public function objectExists(string $bucket, string $key): bool
}
```

**ValidationService.php** - Input validation
```php
class ValidationService {
    public function validateBucketName(string $bucket): bool
    public function validateObjectKey(string $key): bool
    public function validateAccessKey(string $accessKey): bool
}
```

**MultipartService.php** - Multipart upload handling
```php
class MultipartService {
    public function initiateUpload(string $bucket, string $key): string
    public function uploadPart(string $uploadId, int $partNumber, $content): void
    public function completeUpload(string $uploadId, array $parts): void
    public function abortUpload(string $uploadId): void
}
```

### Step 2.2: Create Helper Classes

**S3Response.php** - S3 XML responses
```php
class S3Response {
    public static function error(string $code, string $message, string $resource = ''): void
    public static function listObjects(array $files, string $bucket, string $prefix = ''): void
    public static function initiateMultipartUpload(string $bucket, string $key, string $uploadId): void
    public static function completeMultipartUpload(string $bucket, string $key, string $uploadId): void
    public static function deleteResult(array $deleted, array $errors, bool $quiet = false): void
}
```

## Phase 3: Create Controllers (Day 3-4)

### S3Controller.php

```php
<?php

namespace App\Controllers;

use App\Services\StorageService;
use App\Services\ValidationService;
use App\Services\MultipartService;
use App\Helpers\S3Response;

class S3Controller {
    private $storage;
    private $validation;
    private $multipart;

    public function __construct(
        StorageService $storage,
        ValidationService $validation,
        MultipartService $multipart
    ) {
        $this->storage = $storage;
        $this->validation = $validation;
        $this->multipart = $multipart;
    }

    // PUT /bucket/key - Upload object
    public function putObject($bucket, $key) { }

    // GET /bucket/key - Download object
    public function getObject($bucket, $key) { }

    // DELETE /bucket/key - Delete object
    public function deleteObject($bucket, $key) { }

    // GET /bucket/ - List objects
    public function listObjects($bucket) { }

    // POST /bucket/key?uploads - Initiate multipart
    public function initiateMultipart($bucket, $key) { }

    // PUT /bucket/key?uploadId=...&partNumber=... - Upload part
    public function uploadPart($bucket, $key) { }

    // POST /bucket/key?uploadId=... - Complete multipart
    public function completeMultipart($bucket, $key) { }

    // DELETE /bucket/key?uploadId=... - Abort multipart
    public function abortMultipart($bucket, $key) { }

    // POST /bucket?delete - Multi-object delete
    public function deleteMultiple($bucket) { }
}
```

## Phase 4: Routing & Middleware (Day 4-5)

### app/routes.php

```php
<?php

use App\Controllers\S3Controller;
use App\Middleware\AuthMiddleware;

$controller = new S3Controller(
    new StorageService(),
    new ValidationService(),
    new MultipartService()
);

// Apply authentication middleware to all routes
app()->group(['middleware' => AuthMiddleware::class], function() use ($controller) {
    
    // Object operations
    app()->put('/{bucket}/{key}', [$controller, 'putObject']);
    app()->get('/{bucket}/{key}', [$controller, 'getObject']);
    app()->delete('/{bucket}/{key}', [$controller, 'deleteObject']);
    app()->head('/{bucket}/{key}', [$controller, 'headObject']);
    
    // Bucket operations
    app()->get('/{bucket}/', [$controller, 'listObjects']);
    
    // POST operations (multipart, delete)
    app()->post('/{bucket}/{key}', [$controller, 'postObject']);
    app()->post('/{bucket}/', [$controller, 'postBucket']);
});

// 404 handler
app()->set404(function() {
    S3Response::error('404', 'Not Found');
});
```

### Middleware/AuthMiddleware.php

```php
<?php

namespace App\Middleware;

class AuthMiddleware extends \Leaf\Middleware {
    public function call() {
        $validation = new \App\Services\ValidationService();
        $accessKey = $this->extractAccessKey();
        
        if (!$validation->validateAccessKey($accessKey)) {
            response()->status(401)->exit();
        }
        
        $this->next();
    }
    
    private function extractAccessKey(): ?string {
        // Extract from Authorization header or query params
    }
}
```

## Phase 5: Environment & Configuration (Day 5)

### .env.example
```env
APP_ENV=production
APP_DEBUG=false

# Storage Configuration
DATA_DIR=./data
MAX_REQUEST_SIZE=104857600

# Authentication
ALLOWED_ACCESS_KEYS=minioadmin,another-key

# Logging
LOG_ENABLED=false
LOG_PATH=./logs
```

### config/app.php
```php
<?php

return [
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'timezone' => 'UTC',
];
```

### config/storage.php
```php
<?php

return [
    'data_dir' => env('DATA_DIR', __DIR__ . '/../data'),
    'max_request_size' => env('MAX_REQUEST_SIZE', 100 * 1024 * 1024),
    'allowed_access_keys' => explode(',', env('ALLOWED_ACCESS_KEYS', 'minioadmin')),
];
```

## Phase 6: Testing (Day 6)

### Unit Tests
```php
// tests/Unit/ValidationServiceTest.php
class ValidationServiceTest extends TestCase {
    public function testValidBucketName() {
        $service = new ValidationService();
        $this->assertTrue($service->validateBucketName('mybucket'));
        $this->assertFalse($service->validateBucketName('ab')); // too short
    }
}
```

### Integration Tests
```php
// tests/Integration/S3ControllerTest.php
class S3ControllerTest extends TestCase {
    public function testUploadAndDownloadObject() {
        // Test full upload/download cycle
    }
}
```

## Phase 7: Migration & Deployment (Day 7)

### Migration Steps
1. Run existing test script against old version - capture baseline
2. Deploy new Leaf-based version alongside old version
3. Run same tests against new version
4. Compare results and fix any discrepancies
5. Update documentation
6. Archive old version
7. Deploy to production

### Deployment Checklist
- [ ] All tests passing
- [ ] `.env` file configured
- [ ] `composer install --no-dev --optimize-autoloader`
- [ ] File permissions set correctly
- [ ] Apache/Nginx config updated
- [ ] S3 compatibility tested with s5cmd/aws-cli

## Benefits of Refactoring

### Code Quality
- ✅ **Separation of Concerns**: Controllers, services, helpers clearly separated
- ✅ **Testability**: Each component can be unit tested
- ✅ **Maintainability**: Easier to find and fix bugs
- ✅ **Extensibility**: Add features like logging, metrics, caching easily

### Development Experience
- ✅ **IDE Support**: Better autocomplete and type hinting
- ✅ **Debugging**: Clearer stack traces with framework support
- ✅ **Documentation**: Self-documenting code with proper structure

### Performance
- ⚠️ **Slight Overhead**: Framework adds minimal overhead (~5-10ms)
- ✅ **Optimization Opportunities**: Can add caching, lazy loading later
- ✅ **Profiling**: Framework provides tools for performance monitoring

## Risks & Mitigation

### Risk 1: Breaking Changes
**Mitigation**: 
- Keep old version as fallback
- Comprehensive testing before switch
- Feature flag to toggle between versions

### Risk 2: Increased Complexity
**Mitigation**:
- Document architecture clearly
- Keep services focused and simple
- Regular code reviews

### Risk 3: Performance Regression
**Mitigation**:
- Benchmark before and after
- Profile critical paths
- Optimize hot paths if needed

### Risk 4: Deployment Complexity
**Mitigation**:
- Create deployment script
- Document step-by-step process
- Test on staging first

## Timeline & Effort

| Phase | Duration | Effort | Priority |
|-------|----------|--------|----------|
| 1. Setup | 0.5 days | Low | High |
| 2. Services | 1.5 days | Medium | High |
| 3. Controllers | 1 day | Medium | High |
| 4. Routing | 1 day | Low | High |
| 5. Config | 0.5 days | Low | Medium |
| 6. Testing | 1 day | Medium | High |
| 7. Migration | 1.5 days | High | High |
| **Total** | **7 days** | - | - |

## Alternative Approaches

### Option 1: Keep Current Structure (Do Nothing)
**Pros**: No effort, works fine
**Cons**: Harder to maintain as features grow

### Option 2: Gradual Refactoring
**Pros**: Lower risk, incremental improvements
**Cons**: Takes longer, mixed code styles

### Option 3: Use Different Framework (Slim, Lumen)
**Pros**: More mature, larger community
**Cons**: More opinionated, heavier weight

### Option 4: Custom Lightweight Wrapper
**Pros**: Maximum control, minimal overhead
**Cons**: Reinventing the wheel

## Recommendation

**Proceed with Leaf PHP refactoring** because:
1. ✅ Maintains lightweight nature (Leaf is minimal)
2. ✅ Significant maintainability improvements
3. ✅ Moderate effort (1 week)
4. ✅ Low risk with proper testing
5. ✅ Better foundation for future features

## Next Steps

1. **Review this plan** - Get feedback and approval
2. **Create feature branch** - `git checkout -b refactor/leaf-framework`
3. **Start Phase 1** - Setup dependencies and structure
4. **Daily standups** - Review progress and adjust plan
5. **Code reviews** - Review each phase before moving forward
6. **Testing** - Continuous testing throughout
7. **Documentation** - Update README and create migration guide

## Questions to Answer

- [ ] Do we want to maintain backward compatibility with current config?
- [ ] Should we version the API (e.g., `/v1/bucket/key`)?
- [ ] Do we want to add new features during refactoring or after?
- [ ] What's the rollback strategy if issues arise?
- [ ] Should we containerize (Docker) as part of this effort?
- [ ] Do we need logging/monitoring from day 1 or later?

---

**Prepared by**: Factory Droid  
**Date**: 2024  
**Status**: Draft - Awaiting Approval
