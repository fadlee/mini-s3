# Before vs After Comparison

## Code Example: Handling PUT Request

### BEFORE (Current - Procedural)

```php
// index.php (line 254-273)
case 'PUT':
    // Handle PUT (upload object or part)
    if (isset($_GET['partNumber']) && isset($_GET['uploadId'])) {
        // Upload part
        $uploadId = $_GET['uploadId'];
        $partNumber = $_GET['partNumber'];
        $uploadDir = DATA_DIR . "/{$bucket}/{$key}-temp/{$uploadId}";

        if (!file_exists($uploadDir)) {
            generate_s3_error_response('404', 'Upload ID not found', "/{$bucket}/{$key}");
        }

        $partPath = "{$uploadDir}/{$partNumber}";
        file_put_contents($partPath, file_get_contents('php://input'));

        header('ETag: ' . md5_file($partPath));
        http_response_code(200);
        exit;
    } else {
        // Upload single object
        $filePath = DATA_DIR . "/{$bucket}/{$key}";
        $dir = dirname($filePath);

        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($filePath, file_get_contents('php://input'));
        http_response_code(200);
        exit;
    }
```

### AFTER (With Leaf Framework)

```php
// app/Controllers/S3Controller.php
public function putObject($bucket, $key)
{
    // Validation already done by middleware
    $uploadId = request()->get('uploadId');
    $partNumber = request()->get('partNumber');
    
    try {
        if ($uploadId && $partNumber) {
            // Upload part
            $etag = $this->multipart->uploadPart(
                $bucket, 
                $key, 
                $uploadId, 
                $partNumber, 
                request()->body()
            );
            
            response()->withHeader('ETag', $etag)->status(200);
        } else {
            // Upload single object
            $this->storage->saveObject($bucket, $key, request()->body());
            response()->status(200);
        }
    } catch (NotFoundException $e) {
        S3Response::error('404', $e->getMessage());
    } catch (Exception $e) {
        S3Response::error('500', 'Internal Server Error');
    }
}

// app/Services/StorageService.php
public function saveObject(string $bucket, string $key, $content): void
{
    $filePath = $this->getFilePath($bucket, $key);
    $dir = dirname($filePath);
    
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    
    file_put_contents($filePath, $content);
}

// app/Services/MultipartService.php
public function uploadPart(
    string $bucket, 
    string $key, 
    string $uploadId, 
    int $partNumber, 
    $content
): string {
    $uploadDir = $this->getUploadDir($bucket, $key, $uploadId);
    
    if (!file_exists($uploadDir)) {
        throw new NotFoundException('Upload ID not found');
    }
    
    $partPath = "{$uploadDir}/{$partNumber}";
    file_put_contents($partPath, $content);
    
    return md5_file($partPath);
}
```

## Key Improvements

### 1. **Separation of Concerns**
- **Before**: All logic in one switch statement
- **After**: Controllers handle requests, Services handle business logic

### 2. **Testability**
- **Before**: Hard to unit test, requires mocking $_GET, file_put_contents
- **After**: Each service can be tested independently with mocks

```php
// tests/Unit/MultipartServiceTest.php
public function testUploadPartThrowsExceptionWhenUploadNotFound()
{
    $service = new MultipartService();
    
    $this->expectException(NotFoundException::class);
    $service->uploadPart('bucket', 'key', 'invalid-id', 1, 'content');
}
```

### 3. **Error Handling**
- **Before**: Mix of `exit`, `generate_s3_error_response`, direct headers
- **After**: Consistent exception-based error handling with try/catch

### 4. **Dependency Injection**
- **Before**: Global constants (DATA_DIR), direct file operations
- **After**: Services injected via constructor, easy to swap implementations

### 5. **Request/Response Abstraction**
- **Before**: Direct `$_GET`, `file_get_contents('php://input')`, `header()`
- **After**: Framework abstractions: `request()->get()`, `request()->body()`, `response()->withHeader()`

## File Structure Comparison

### BEFORE
```
mini-s3/
├── .htaccess
├── index.php           # 479 lines - everything
├── config.example.php
├── data/
└── test-s5cmd.sh
```

### AFTER
```
mini-s3/
├── public/
│   ├── index.php       # 20 lines - bootstrapping
│   └── .htaccess
├── app/
│   ├── Controllers/
│   │   └── S3Controller.php       # 200 lines
│   ├── Middleware/
│   │   └── AuthMiddleware.php     # 50 lines
│   ├── Services/
│   │   ├── StorageService.php     # 100 lines
│   │   ├── ValidationService.php  # 80 lines
│   │   └── MultipartService.php   # 120 lines
│   ├── Helpers/
│   │   └── S3Response.php         # 100 lines
│   └── routes.php                 # 40 lines
├── config/
│   ├── app.php
│   └── storage.php
├── tests/
│   ├── Unit/           # 10+ test files
│   └── Integration/    # 5+ test files
├── data/
├── vendor/
├── .env.example
├── composer.json
└── README.md
```

## Routing Comparison

### BEFORE
```php
// Manual switch/case
$method = $_SERVER['REQUEST_METHOD'];
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path_parts = explode('/', trim($request_uri, '/'));
$bucket = $path_parts[0] ?? '';
$key = implode('/', array_slice($path_parts, 1));

switch ($method) {
    case 'PUT':
        // 20 lines of logic
        break;
    case 'GET':
        // 80 lines of logic
        break;
    case 'DELETE':
        // 30 lines of logic
        break;
    // etc...
}
```

### AFTER
```php
// app/routes.php
app()->put('/{bucket}/{key}', [S3Controller::class, 'putObject']);
app()->get('/{bucket}/{key}', [S3Controller::class, 'getObject']);
app()->get('/{bucket}/', [S3Controller::class, 'listObjects']);
app()->delete('/{bucket}/{key}', [S3Controller::class, 'deleteObject']);
app()->post('/{bucket}/{key}', [S3Controller::class, 'postObject']);
app()->post('/{bucket}/', [S3Controller::class, 'postBucket']);
```

## Middleware Example

### BEFORE
```php
// Inline at line 242
function auth_check()
{
    $authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $access_key_id = extract_access_key_id($authorization);
    if (!$access_key_id || !in_array($access_key_id, ALLOWED_ACCESS_KEYS)) {
        http_response_code(401);
        exit;
    }
    return true;
}

// Called manually before each operation
auth_check();
```

### AFTER
```php
// app/Middleware/AuthMiddleware.php
class AuthMiddleware extends \Leaf\Middleware
{
    public function call()
    {
        $accessKey = $this->extractAccessKey();
        
        if (!$this->validation->validateAccessKey($accessKey)) {
            response()->status(401)->json([
                'error' => 'Unauthorized'
            ])->exit();
        }
        
        $this->next();
    }
}

// Applied to all routes automatically
app()->group(['middleware' => AuthMiddleware::class], function() {
    // All routes protected
});
```

## Configuration Comparison

### BEFORE
```php
// index.php (top of file)
define('DATA_DIR', __DIR__ . '/data');
define('ALLOWED_ACCESS_KEYS', ['minioadmin']);
define('MAX_REQUEST_SIZE', 100 * 1024 * 1024);
```

### AFTER
```php
// .env
DATA_DIR=./data
ALLOWED_ACCESS_KEYS=minioadmin,user2,user3
MAX_REQUEST_SIZE=104857600

// config/storage.php
return [
    'data_dir' => env('DATA_DIR', __DIR__ . '/../data'),
    'allowed_access_keys' => explode(',', env('ALLOWED_ACCESS_KEYS')),
    'max_request_size' => env('MAX_REQUEST_SIZE', 100 * 1024 * 1024),
];

// Usage in code
$config = config('storage');
$dataDir = $config['data_dir'];
```

## Testing Comparison

### BEFORE
```bash
# Manual testing only
./test-s5cmd.sh

# No unit tests
# No integration tests
# Manual verification
```

### AFTER
```bash
# Automated testing
composer test

# Unit tests
./vendor/bin/phpunit tests/Unit

# Integration tests  
./vendor/bin/phpunit tests/Integration

# Code coverage
./vendor/bin/phpunit --coverage-html coverage/

# Static analysis
./vendor/bin/phpstan analyse
```

## Performance Impact

### Overhead Comparison
| Metric | Before | After | Difference |
|--------|--------|-------|------------|
| Memory | ~2MB | ~4MB | +2MB (framework) |
| Response Time | ~5ms | ~7ms | +2ms (routing) |
| File Size | 479 lines | ~650 lines | +36% (but organized) |
| Maintainability | Low | High | 🚀 |
| Testability | None | High | 🚀 |

### Real-World Impact
- **Small files (<1MB)**: Negligible (~1-2ms overhead)
- **Large files (>10MB)**: No difference (I/O bound)
- **Memory**: Framework overhead is constant, doesn't scale with file size
- **Scalability**: Better with framework (caching, optimization opportunities)

## Migration Risk Matrix

| Risk | Probability | Impact | Mitigation |
|------|-------------|--------|------------|
| Breaking API | Low | High | Comprehensive testing |
| Performance regression | Low | Medium | Benchmarking |
| Increased complexity | Medium | Low | Documentation |
| Deployment issues | Medium | Medium | Staging environment |
| Developer learning curve | Medium | Low | Code examples |

## Summary

### Choose Refactoring If:
- ✅ You plan to add more features
- ✅ You want automated testing
- ✅ Multiple developers will work on it
- ✅ You need better error handling
- ✅ You want easier debugging

### Keep Current If:
- ✅ No plans for new features
- ✅ Single developer maintaining
- ✅ Performance is critical (every ms counts)
- ✅ Deployment complexity is a concern
- ✅ "If it ain't broke, don't fix it" philosophy

## Conclusion

The refactoring provides **significant long-term benefits** with **moderate short-term cost**. The improved structure makes the codebase more maintainable, testable, and extensible while maintaining the lightweight nature that makes mini-s3 attractive.

**Recommendation**: Proceed with refactoring, especially if you anticipate adding features like versioning, logging, metrics, or CDN integration in the future.
