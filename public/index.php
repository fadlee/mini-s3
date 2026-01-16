<?php

/**
 * Mini-S3 Server - Entry Point
 * 
 * Lightweight S3-compatible object storage using Fat-Free Framework
 */

require_once __DIR__ . '/../vendor/autoload.php';

$f3 = Base::instance();

$config = require __DIR__ . '/../config/config.php';
foreach ($config as $key => $value) {
    $f3->set($key, $value);
}

$f3->set('AUTOLOAD', __DIR__ . '/../app/');
$f3->set('DEBUG', 3);

if (!file_exists($f3->get('DATA_DIR'))) {
    mkdir($f3->get('DATA_DIR'), 0777, true);
}

$f3->route('GET /', function ($f3) {
    header('Content-Type: application/json');
    echo json_encode([
        'app' => 'Mini-S3',
        'version' => '2.0.0',
        'framework' => 'Fat-Free Framework',
        'php' => PHP_VERSION
    ]);
});

$f3->route('GET /@bucket', 'App\Controllers\BucketController->listObjects');

$f3->route('POST /@bucket?delete=*', 'App\Controllers\BucketController->multiDelete');

$f3->route('GET /@bucket/*', function ($f3, $params) {
    $params['key'] = $params[2] ?? $params['*'] ?? '';
    $controller = new App\Controllers\ObjectController();
    $controller->beforeRoute($f3);
    $controller->get($f3, $params);
});
$f3->route('PUT /@bucket/*', function ($f3, $params) {
    $uploadId = $f3->get('GET.uploadId');
    $partNumber = $f3->get('GET.partNumber');
    
    $params['key'] = $params[2] ?? $params['*'] ?? '';
    
    if ($uploadId && $partNumber) {
        $controller = new App\Controllers\MultipartController();
        $controller->beforeRoute($f3);
        $controller->uploadPart($f3, $params);
    } else {
        $controller = new App\Controllers\ObjectController();
        $controller->beforeRoute($f3);
        $controller->put($f3, $params);
    }
});
$f3->route('DELETE /@bucket/*', function ($f3, $params) {
    $uploadId = $f3->get('GET.uploadId');
    
    $params['key'] = $params[2] ?? $params['*'] ?? '';
    
    if ($uploadId) {
        $controller = new App\Controllers\MultipartController();
        $controller->beforeRoute($f3);
        $controller->abortUpload($f3, $params);
    } else {
        $controller = new App\Controllers\ObjectController();
        $controller->beforeRoute($f3);
        $controller->delete($f3, $params);
    }
});
$f3->route('HEAD /@bucket/*', function ($f3, $params) {
    $params['key'] = $params[2] ?? $params['*'] ?? '';
    $controller = new App\Controllers\ObjectController();
    $controller->beforeRoute($f3);
    $controller->head($f3, $params);
});
$f3->route('POST /@bucket/*', function ($f3, $params) {
    $params['key'] = $params[2] ?? $params['*'] ?? '';
    $controller = new App\Controllers\MultipartController();
    $controller->beforeRoute($f3);
    $controller->handlePost($f3, $params);
});

$f3->set('ONERROR', function($f3) {
    $error = $f3->get('ERROR');
    error_log("F3 Error: " . print_r($error, true));
    
    header('Content-Type: application/xml');
    http_response_code(500);
    echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>500</Code><Message>' . htmlspecialchars($error['text'] ?? 'Internal Server Error') . '</Message></Error>';
});

if (isset($_SERVER['CONTENT_LENGTH']) && $_SERVER['CONTENT_LENGTH'] > $f3->get('MAX_REQUEST_SIZE')) {
    header('Content-Type: application/xml');
    http_response_code(413);
    echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>413</Code><Message>Request too large</Message></Error>';
    exit;
}

$f3->run();
