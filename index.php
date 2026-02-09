<?php

declare(strict_types=1);

require_once __DIR__ . '/src/Config/ConfigLoader.php';
require_once __DIR__ . '/src/Auth/AuthException.php';
require_once __DIR__ . '/src/Auth/SigV4Authenticator.php';
require_once __DIR__ . '/src/Http/RequestContext.php';
require_once __DIR__ . '/src/Storage/FileStorage.php';
require_once __DIR__ . '/src/S3/S3Response.php';
require_once __DIR__ . '/src/S3/S3Router.php';

use MiniS3\Auth\SigV4Authenticator;
use MiniS3\Config\ConfigLoader;
use MiniS3\Http\RequestContext;
use MiniS3\S3\S3Response;
use MiniS3\S3\S3Router;
use MiniS3\Storage\FileStorage;

try {
    $config = ConfigLoader::load(__DIR__);

    $request = RequestContext::fromGlobals();
    $storage = new FileStorage((string) $config['DATA_DIR']);
    $response = new S3Response();
    $authenticator = new SigV4Authenticator(
        (array) $config['CREDENTIALS'],
        (array) $config['ALLOWED_ACCESS_KEYS'],
        (bool) $config['ALLOW_LEGACY_ACCESS_KEY_ONLY'],
        (int) $config['CLOCK_SKEW_SECONDS'],
        (int) $config['MAX_PRESIGN_EXPIRES'],
        (string) ($config['AUTH_DEBUG_LOG'] ?? '')
    );

    $router = new S3Router(
        $request,
        $storage,
        $response,
        $authenticator,
        (int) $config['MAX_REQUEST_SIZE']
    );

    $router->handle();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><Error><Code>InternalError</Code><Message>Internal server error</Message></Error>';
    exit;
}
