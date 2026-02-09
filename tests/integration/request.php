<?php

declare(strict_types=1);

if ($argc < 4) {
    fwrite(STDERR, "Usage: php request.php METHOD URI META_FILE [Header: Value ...]\n");
    exit(1);
}

$method = strtoupper((string) $argv[1]);
$uri = (string) $argv[2];
$metaFile = (string) $argv[3];

$headers = [];
for ($i = 4; $i < $argc; $i++) {
    $line = (string) $argv[$i];
    $parts = explode(':', $line, 2);
    if (count($parts) !== 2) {
        continue;
    }

    $name = trim($parts[0]);
    $value = trim($parts[1]);
    if ($name === '') {
        continue;
    }

    $headers[$name] = $value;
}

$host = $headers['Host'] ?? $headers['host'] ?? 'mini-s3.test';
$serverName = $host;
$serverPort = 80;
if (str_contains($host, ':')) {
    [$serverName, $port] = explode(':', $host, 2);
    if (ctype_digit($port)) {
        $serverPort = (int) $port;
    }
}

$_GET = [];
$query = parse_url($uri, PHP_URL_QUERY);
if (is_string($query) && $query !== '') {
    parse_str($query, $_GET);
}

$_POST = [];
$_COOKIE = [];
$_FILES = [];
$_REQUEST = array_merge($_GET, $_POST);

$_SERVER = [
    'REQUEST_METHOD' => $method,
    'REQUEST_URI' => $uri,
    'SERVER_NAME' => $serverName,
    'SERVER_PORT' => (string) $serverPort,
    'HTTPS' => 'off',
];

foreach ($headers as $name => $value) {
    $normalized = strtoupper(str_replace('-', '_', $name));
    if ($normalized === 'CONTENT_TYPE') {
        $_SERVER['CONTENT_TYPE'] = $value;
        continue;
    }

    if ($normalized === 'CONTENT_LENGTH') {
        $_SERVER['CONTENT_LENGTH'] = $value;
        continue;
    }

    $_SERVER['HTTP_' . $normalized] = $value;
}

register_shutdown_function(static function () use ($metaFile): void {
    $status = http_response_code();
    if ($status === false) {
        $status = 200;
    }

    $payload = [
        'status' => $status,
        'headers' => headers_list(),
    ];

    file_put_contents($metaFile, json_encode($payload, JSON_UNESCAPED_SLASHES));
});

$root = realpath(__DIR__ . '/../../');
if ($root === false) {
    fwrite(STDERR, "Unable to locate project root\n");
    exit(1);
}

chdir($root);
require $root . '/index.php';
