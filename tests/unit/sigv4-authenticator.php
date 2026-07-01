<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Auth/AuthException.php';
require_once __DIR__ . '/../../src/Auth/SigV4Authenticator.php';
require_once __DIR__ . '/../../src/Http/RequestContext.php';

use MiniS3\Auth\AuthException;
use MiniS3\Auth\SigV4Authenticator;
use MiniS3\Http\RequestContext;

$failures = 0;
$tests = 0;

function check(bool $condition, string $message): void
{
    global $failures, $tests;
    $tests++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}" . PHP_EOL);
    }
}

function expectAuthException(callable $fn, string $expectedS3Code, string $message): void
{
    global $failures, $tests;
    $tests++;
    try {
        $fn();
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: expected AuthException {$expectedS3Code} but none thrown" . PHP_EOL);
    } catch (AuthException $e) {
        if ($e->getS3Code() !== $expectedS3Code) {
            $failures++;
            fwrite(STDERR, "[FAIL] {$message}: expected s3code={$expectedS3Code} got={$e->getS3Code()} msg={$e->getMessage()}" . PHP_EOL);
        }
    }
}

function expectNoException(callable $fn, string $message): void
{
    global $failures, $tests;
    $tests++;
    try {
        $fn();
    } catch (AuthException $e) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: unexpected AuthException s3code={$e->getS3Code()} msg={$e->getMessage()}" . PHP_EOL);
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, "[FAIL] {$message}: unexpected " . get_class($e) . " msg={$e->getMessage()}" . PHP_EOL);
    }
}

// ---------------------------------------------------------------------------
// SigV4 signing helpers — independent reimplementation of the AWS4-HMAC-SHA256
// algorithm, mirroring tests/integration/sigv4.php. Used to produce valid
// signatures so the "happy path" of the authenticator can be exercised.
// ---------------------------------------------------------------------------

function awsPercentEncode(string $value): string
{
    return str_replace('%7E', '~', rawurlencode($value));
}

function normalizeHeaderValue(string $value): string
{
    return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
}

function canonicalUri(string $path): string
{
    $segments = explode('/', $path === '' ? '/' : $path);
    $encoded = [];
    foreach ($segments as $segment) {
        $encoded[] = awsPercentEncode(rawurldecode($segment));
    }
    $uri = implode('/', $encoded);
    if ($uri === '') {
        return '/';
    }
    if ($uri[0] !== '/') {
        $uri = '/' . $uri;
    }
    return $uri;
}

function canonicalQueryString(string $rawQuery, array $excludedKeys): string
{
    if ($rawQuery === '') {
        return '';
    }
    $exclude = [];
    foreach ($excludedKeys as $k) {
        $exclude[$k] = true;
    }
    $pairs = [];
    foreach (explode('&', $rawQuery) as $pair) {
        if ($pair === '') {
            continue;
        }
        $parts = explode('=', $pair, 2);
        $decodedKey = rawurldecode($parts[0]);
        $decodedValue = rawurldecode($parts[1] ?? '');
        if (isset($exclude[$decodedKey])) {
            continue;
        }
        $pairs[] = [awsPercentEncode($decodedKey), awsPercentEncode($decodedValue)];
    }
    usort($pairs, static function (array $a, array $b): int {
        if ($a[0] === $b[0]) {
            return $a[1] <=> $b[1];
        }
        return $a[0] <=> $b[0];
    });
    $out = [];
    foreach ($pairs as [$k, $v]) {
        $out[] = $k . '=' . $v;
    }
    return implode('&', $out);
}

function calculateSignature(string $secretKey, string $date, string $region, string $service, string $stringToSign): string
{
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    return hash_hmac('sha256', $stringToSign, $kSigning);
}

/**
 * Build a valid Authorization header for a header-auth request.
 */
function signHeaderAuth(
    string $method,
    string $host,
    string $path,
    string $query,
    string $accessKey,
    string $secretKey,
    string $payloadHash,
    string $amzDate,
    string $region = 'us-east-1',
    array $signedHeaders = ['host', 'x-amz-content-sha256', 'x-amz-date']
): string {
    $date = substr($amzDate, 0, 8);
    $scope = $date . '/' . $region . '/s3/aws4_request';

    $headerValues = [
        'host' => $host,
        'x-amz-content-sha256' => $payloadHash,
        'x-amz-date' => $amzDate,
    ];

    $sorted = $signedHeaders;
    sort($sorted, SORT_STRING);
    $canonicalHeaders = '';
    foreach ($sorted as $name) {
        $canonicalHeaders .= $name . ':' . normalizeHeaderValue($headerValues[$name]) . "\n";
    }
    $signedHeadersLine = implode(';', $sorted);

    $canonicalRequest = implode("\n", [
        strtoupper($method),
        canonicalUri($path),
        canonicalQueryString($query, []),
        $canonicalHeaders,
        $signedHeadersLine,
        $payloadHash,
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $signature = calculateSignature($secretKey, $date, $region, 's3', $stringToSign);

    return 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $accessKey . '/' . $scope
        . ', SignedHeaders=' . $signedHeadersLine
        . ', Signature=' . $signature;
}

/**
 * Build a presigned URL query string (including X-Amz-Signature) for a presign request.
 * Returns the full query string to append to the path in the request URI.
 */
function signPresigned(
    string $method,
    string $host,
    string $path,
    string $existingQuery,
    string $accessKey,
    string $secretKey,
    int $expires,
    int $offsetSeconds,
    string $region = 'us-east-1'
): string {
    $amzDate = gmdate('Ymd\THis\Z', time() + $offsetSeconds);
    $date = substr($amzDate, 0, 8);
    $scope = $date . '/' . $region . '/s3/aws4_request';

    $pairs = [];
    if ($existingQuery !== '') {
        foreach (explode('&', $existingQuery) as $pair) {
            if ($pair === '') {
                continue;
            }
            $p = explode('=', $pair, 2);
            $pairs[] = [rawurldecode($p[0]), rawurldecode($p[1] ?? '')];
        }
    }
    $pairs[] = ['X-Amz-Algorithm', 'AWS4-HMAC-SHA256'];
    $pairs[] = ['X-Amz-Credential', $accessKey . '/' . $scope];
    $pairs[] = ['X-Amz-Date', $amzDate];
    $pairs[] = ['X-Amz-Expires', (string) $expires];
    $pairs[] = ['X-Amz-SignedHeaders', 'host'];

    // Build canonical query excluding X-Amz-Signature (not yet present).
    $encoded = [];
    foreach ($pairs as [$k, $v]) {
        $encoded[] = [awsPercentEncode($k), awsPercentEncode($v)];
    }
    usort($encoded, static function (array $a, array $b): int {
        if ($a[0] === $b[0]) {
            return $a[1] <=> $b[1];
        }
        return $a[0] <=> $b[0];
    });
    $canonicalQuery = '';
    foreach ($encoded as [$k, $v]) {
        $canonicalQuery .= ($canonicalQuery === '' ? '' : '&') . $k . '=' . $v;
    }

    $canonicalRequest = implode("\n", [
        strtoupper($method),
        canonicalUri($path),
        $canonicalQuery,
        'host:' . normalizeHeaderValue($host) . "\n",
        'host',
        'UNSIGNED-PAYLOAD',
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $scope,
        hash('sha256', $canonicalRequest),
    ]);

    $signature = calculateSignature($secretKey, $date, $region, 's3', $stringToSign);
    $pairs[] = ['X-Amz-Signature', $signature];

    $final = [];
    foreach ($pairs as [$k, $v]) {
        $final[] = awsPercentEncode($k) . '=' . awsPercentEncode($v);
    }
    return implode('&', $final);
}

/**
 * Build a RequestContext from a server array.
 */
function makeRequest(string $method, string $path, string $query, array $headers, array $extraServer = []): RequestContext
{
    $server = $extraServer;
    foreach ($headers as $name => $value) {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if ($name === 'content-type') {
            $server['CONTENT_TYPE'] = $value;
            continue;
        }
        if ($name === 'content-length') {
            $server['CONTENT_LENGTH'] = $value;
            continue;
        }
        $server[$key] = $value;
    }
    $requestUri = $path;
    if ($query !== '') {
        $requestUri .= '?' . $query;
    }
    return new RequestContext($method, $requestUri, $server);
}

// ---------------------------------------------------------------------------
// Test fixtures
// ---------------------------------------------------------------------------

$accessKey = 'AKIAIOSFODNN7EXAMPLE';
$secretKey = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
$credentials = [$accessKey => $secretKey];
$host = 'example.com';
$path = '/bucket/object.txt';
$payloadHash = hash('sha256', 'hello world');

function makeAuth(
    array $credentials,
    array $allowedAccessKeys = [],
    bool $allowLegacy = false,
    int $clockSkew = 900,
    int $maxPresign = 604800,
    string $debugLog = '',
    bool $allowFallbacks = false
): SigV4Authenticator {
    return new SigV4Authenticator(
        $credentials,
        $allowedAccessKeys,
        $allowLegacy,
        $clockSkew,
        $maxPresign,
        $debugLog,
        $allowFallbacks
    );
}

// ===========================================================================
// Header auth — happy path
// ===========================================================================

$amzDate = gmdate('Ymd\THis\Z');
$authz = signHeaderAuth('GET', $host, $path, '', $accessKey, $secretKey, $payloadHash, $amzDate);
$auth = makeAuth($credentials);

expectNoException(function () use ($auth, $authz, $amzDate, $host, $path, $payloadHash): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authz,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'valid header auth succeeds');

// Different method + path with special chars
$authz2 = signHeaderAuth('PUT', $host, '/bucket/my file.txt', '', $accessKey, $secretKey, $payloadHash, $amzDate);
expectNoException(function () use ($auth, $authz2, $amzDate, $host, $payloadHash): void {
    $req = makeRequest('PUT', '/bucket/my file.txt', '', [
        'host' => $host,
        'authorization' => $authz2,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'valid header auth with space in path succeeds');

// With query string
$authz3 = signHeaderAuth('GET', $host, $path, 'prefix=foo&max-keys=10', $accessKey, $secretKey, $payloadHash, $amzDate);
expectNoException(function () use ($auth, $authz3, $amzDate, $host, $path, $payloadHash): void {
    $req = makeRequest('GET', $path, 'prefix=foo&max-keys=10', [
        'host' => $host,
        'authorization' => $authz3,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'valid header auth with query string succeeds');

// ===========================================================================
// Header auth — rejection paths
// ===========================================================================

// Bad signature
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $badAuthz = signHeaderAuth('GET', $host, $path, '', $accessKey, $secretKey, $payloadHash, $amzDate)
        . 'deadbeef';
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $badAuthz,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'SignatureDoesNotMatch', 'tampered signature rejected');

// Signature signed against different host (no fallback) -> mismatch
expectAuthException(function () use ($auth, $accessKey, $secretKey, $path, $payloadHash, $amzDate): void {
    $authz = signHeaderAuth('GET', 'other-host.com', $path, '', $accessKey, $secretKey, $payloadHash, $amzDate);
    $req = makeRequest('GET', $path, '', [
        'host' => 'example.com',
        'authorization' => $authz,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'SignatureDoesNotMatch', 'signature for wrong host rejected without fallback');

// Invalid access key id
expectAuthException(function () use ($secretKey, $credentials, $host, $path, $payloadHash, $amzDate): void {
    $authz = signHeaderAuth('GET', $host, $path, '', 'UNKNOWNKEY', $secretKey, $payloadHash, $amzDate);
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authz,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth = makeAuth($credentials);
    $auth->authenticate($req);
}, 'InvalidAccessKeyId', 'unknown access key id rejected');

// Missing x-amz-date
expectAuthException(function () use ($auth, $authz, $host, $path, $payloadHash): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authz,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AccessDenied', 'missing x-amz-date rejected');

// Missing x-amz-content-sha256
expectAuthException(function () use ($auth, $authz, $host, $path, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authz,
        'x-amz-date' => $amzDate,
    ]);
    $auth->authenticate($req);
}, 'AccessDenied', 'missing x-amz-content-sha256 rejected');

// Clock skew — too old
$oldDate = gmdate('Ymd\THis\Z', time() - 10000);
$authzOld = signHeaderAuth('GET', $host, $path, '', $accessKey, $secretKey, $payloadHash, $oldDate);
expectAuthException(function () use ($credentials, $host, $path, $payloadHash, $oldDate, $authzOld): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authzOld,
        'x-amz-date' => $oldDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth = makeAuth($credentials);
    $auth->authenticate($req);
}, 'RequestTimeTooSkewed', 'old timestamp rejected for clock skew');

// Clock skew — too far in future
$futureDate = gmdate('Ymd\THis\Z', time() + 10000);
$authzFuture = signHeaderAuth('GET', $host, $path, '', $accessKey, $secretKey, $payloadHash, $futureDate);
expectAuthException(function () use ($credentials, $host, $path, $payloadHash, $futureDate, $authzFuture): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authzFuture,
        'x-amz-date' => $futureDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth = makeAuth($credentials);
    $auth->authenticate($req);
}, 'RequestTimeTooSkewed', 'future timestamp rejected for clock skew');

// Invalid x-amz-date format
expectAuthException(function () use ($auth, $authz, $host, $path, $payloadHash): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authz,
        'x-amz-date' => 'not-a-date',
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AccessDenied', 'invalid x-amz-date format rejected');

// Wrong algorithm prefix
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'Bearer some.token',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AccessDenied', 'non-SigV4 authorization header rejected (no legacy)');

// Malformed authorization — missing Credential
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 SignedHeaders=host;x-amz-date, Signature=abc',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AccessDenied', 'authorization missing Credential rejected');

// Malformed authorization — missing Signature
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/20260101/us-east-1/s3/aws4_request, SignedHeaders=host',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AccessDenied', 'authorization missing Signature rejected');

// Credential scope — wrong parts count
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/20260101/us-east-1, SignedHeaders=host, Signature=abc',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'credential scope wrong parts count rejected');

// Credential scope — bad date format
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/2026010/us-east-1/s3/aws4_request, SignedHeaders=host, Signature=abc',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'credential scope bad date rejected');

// Credential scope — wrong service
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/20260101/us-east-1/ec2/aws4_request, SignedHeaders=host, Signature=abc',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'credential scope wrong service rejected');

// SignedHeaders — empty (parseAuthorizationHeader catches missing/empty as AccessDenied)
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/20260101/us-east-1/s3/aws4_request, SignedHeaders=, Signature=abc',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AccessDenied', 'empty SignedHeaders rejected');

// SignedHeaders — not sorted
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/20260101/us-east-1/s3/aws4_request, SignedHeaders=x-amz-date;host, Signature=abc',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'unsorted SignedHeaders rejected');

// SignedHeaders — duplicate
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/20260101/us-east-1/s3/aws4_request, SignedHeaders=host;host, Signature=abc',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'duplicate SignedHeaders rejected');

// SignedHeaders — invalid characters (space is not [a-z0-9-])
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'AWS4-HMAC-SHA256 Credential=' . $accessKey . '/20260101/us-east-1/s3/aws4_request, SignedHeaders=host;inval id, Signature=abc',
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'SignedHeaders with invalid chars rejected');

// SignedHeaders — uppercase is normalized (lowercased), not rejected; verify
// it still authenticates when the signature is computed against the lowercased set
$authzUpper = signHeaderAuth('GET', $host, $path, '', $accessKey, $secretKey, $payloadHash, $amzDate);
expectNoException(function () use ($auth, $authzUpper, $host, $path, $payloadHash, $amzDate): void {
    // Send SignedHeaders with mixed case; the authenticator lowercases them
    $authzMixed = preg_replace('/SignedHeaders=([^,]+)/', 'SignedHeaders=Host;X-Amz-Content-Sha256;X-Amz-Date', $authzUpper);
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authzMixed,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $auth->authenticate($req);
}, 'uppercase SignedHeaders normalized and accepted');

// Missing signed header in request
expectAuthException(function () use ($auth, $accessKey, $secretKey, $host, $path, $payloadHash, $amzDate): void {
    // Sign with x-amz-date but don't send it on the request
    $authz = signHeaderAuth('GET', $host, $path, '', $accessKey, $secretKey, $payloadHash, $amzDate);
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authz,
        'x-amz-content-sha256' => $payloadHash,
        // x-amz-date deliberately omitted
    ]);
    $auth->authenticate($req);
}, 'AccessDenied', 'missing signed header in request rejected');

// ===========================================================================
// Presigned URL — happy path
// ===========================================================================

$presignQuery = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 3600, 0);
expectNoException(function () use ($credentials, $presignQuery, $host, $path): void {
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $presignQuery, ['host' => $host]);
    $auth->authenticate($req);
}, 'valid presigned URL succeeds');

// Presigned with existing query params
$presignQuery2 = signPresigned('GET', $host, $path, 'prefix=foo', $accessKey, $secretKey, 3600, 0);
expectNoException(function () use ($credentials, $presignQuery2, $host, $path): void {
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $presignQuery2, ['host' => $host]);
    $auth->authenticate($req);
}, 'valid presigned URL with existing query succeeds');

// ===========================================================================
// Presigned URL — rejection paths
// ===========================================================================

// Bad presigned signature
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path): void {
    $q = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 3600, 0);
    // Tamper the signature
    $q = preg_replace('/X-Amz-Signature=([0-9a-f]+)/', 'X-Amz-Signature=deadbeef', $q);
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $q, ['host' => $host]);
    $auth->authenticate($req);
}, 'SignatureDoesNotMatch', 'tampered presigned signature rejected');

// Expired presigned URL (offset -7200, expires 3600 -> already expired)
$expiredQuery = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 3600, -7200);
expectAuthException(function () use ($credentials, $expiredQuery, $host, $path): void {
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $expiredQuery, ['host' => $host]);
    $auth->authenticate($req);
}, 'ExpiredToken', 'expired presigned URL rejected');

// Future-dated presigned URL (beyond clock skew)
$futureQuery = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 3600, 10000);
expectAuthException(function () use ($credentials, $futureQuery, $host, $path): void {
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $futureQuery, ['host' => $host]);
    $auth->authenticate($req);
}, 'RequestTimeTooSkewed', 'future-dated presigned URL rejected');

// X-Amz-Expires = 0 (invalid)
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path): void {
    $q = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 0, 0);
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $q, ['host' => $host]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'presigned expires=0 rejected');

// X-Amz-Expires > max (604801)
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path): void {
    $q = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 604801, 0);
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $q, ['host' => $host]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'presigned expires > max rejected');

// Missing X-Amz-Date in presigned
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path): void {
    $q = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 3600, 0);
    $q = preg_replace('/&X-Amz-Date=[^&]+/', '', $q);
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $q, ['host' => $host]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'presigned missing X-Amz-Date rejected');

// Missing X-Amz-Signature in presigned
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path): void {
    $q = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 3600, 0);
    $q = preg_replace('/&X-Amz-Signature=[^&]+/', '', $q);
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $q, ['host' => $host]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'presigned missing X-Amz-Signature rejected');

// Wrong X-Amz-Algorithm
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path): void {
    $q = signPresigned('GET', $host, $path, '', $accessKey, $secretKey, 3600, 0);
    $q = str_replace('X-Amz-Algorithm=AWS4-HMAC-SHA256', 'X-Amz-Algorithm=AWS4-HMAC-SHA1', $q);
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $q, ['host' => $host]);
    $auth->authenticate($req);
}, 'AuthorizationQueryParametersError', 'presigned wrong algorithm rejected');

// Unknown access key id in presigned
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path): void {
    $q = signPresigned('GET', $host, $path, '', 'UNKNOWNKEY', $secretKey, 3600, 0);
    $auth = makeAuth($credentials);
    $req = makeRequest('GET', $path, $q, ['host' => $host]);
    $auth->authenticate($req);
}, 'InvalidAccessKeyId', 'presigned unknown access key id rejected');

// ===========================================================================
// Legacy access-key-only mode
// ===========================================================================

$legacyAuth = makeAuth($credentials, [$accessKey], true);

// Valid legacy: access key in whitelist, non-SigV4 auth header containing
// Credential=KEY/ pattern. Legacy mode only kicks in when the Authorization
// header does NOT start with AWS4-HMAC-SHA256 (otherwise full SigV4 applies).
expectNoException(function () use ($legacyAuth, $host, $path, $accessKey): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => 'CustomAuth Credential=' . $accessKey . '/scope',
    ]);
    $legacyAuth->authenticate($req);
}, 'legacy access-key-only mode accepts whitelisted key');

// Legacy: access key NOT in whitelist -> falls through to full auth -> SignatureDoesNotMatch
expectAuthException(function () use ($legacyAuth, $host, $path, $amzDate, $payloadHash): void {
    $authz = 'AWS4-HMAC-SHA256 Credential=OTHERKEY/20260101/us-east-1/s3/aws4_request, SignedHeaders=host, Signature=abc';
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authz,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $legacyAuth->authenticate($req);
}, 'InvalidAccessKeyId', 'legacy mode rejects key not in whitelist and not in credentials');

// Legacy disabled, no auth header at all -> AccessDenied
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path): void {
    $auth = makeAuth($credentials, [$accessKey], false);
    $req = makeRequest('GET', $path, '', ['host' => $host]);
    $auth->authenticate($req);
}, 'AccessDenied', 'no auth at all rejected when legacy disabled');

// Legacy enabled but no access key present at all -> AccessDenied
expectAuthException(function () use ($legacyAuth, $host, $path): void {
    $req = makeRequest('GET', $path, '', ['host' => $host]);
    $legacyAuth->authenticate($req);
}, 'AccessDenied', 'no auth at all rejected even when legacy enabled');

// Legacy mode does NOT bypass presigned signature verification: the
// isPresignedRequest() check routes to authenticatePresignedRequest() which
// fully verifies the signature regardless of legacy mode. A presigned URL
// signed with the wrong secret must be rejected even when the access key is
// in the legacy whitelist.
expectAuthException(function () use ($accessKey, $secretKey, $credentials, $host, $path, $amzDate): void {
    $q = signPresigned('GET', $host, $path, '', $accessKey, 'wrong-secret', 3600, 0);
    $auth = makeAuth($credentials, [$accessKey], true);
    $req = makeRequest('GET', $path, $q, ['host' => $host]);
    $auth->authenticate($req);
}, 'SignatureDoesNotMatch', 'legacy mode does not bypass presigned signature verification');

// ===========================================================================
// Host candidate fallbacks
// ===========================================================================

// Fallback enabled: sign against X-Forwarded-Host value, request Host differs -> authenticates
$fallbackAuth = makeAuth($credentials, [], false, 900, 604800, '', true);
$forwardedHost = 'public.example.com';
$authzFwd = signHeaderAuth('GET', $forwardedHost, $path, '', $accessKey, $secretKey, $payloadHash, $amzDate);
expectNoException(function () use ($fallbackAuth, $authzFwd, $host, $forwardedHost, $path, $payloadHash, $amzDate): void {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authzFwd,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
        'x-forwarded-host' => $forwardedHost,
    ]);
    $fallbackAuth->authenticate($req);
}, 'host candidate fallback authenticates via X-Forwarded-Host');

// Fallback disabled: same setup -> rejected
expectAuthException(function () use ($credentials, $host, $forwardedHost, $path, $payloadHash, $amzDate, $authzFwd): void {
    $auth = makeAuth($credentials, [], false, 900, 604800, '', false);
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authzFwd,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
        'x-forwarded-host' => $forwardedHost,
    ]);
    $auth->authenticate($req);
}, 'SignatureDoesNotMatch', 'host candidate fallback disabled rejects forwarded-host signature');

// Fallback via SERVER_NAME
$authzServerName = signHeaderAuth('GET', 'internal.local', $path, '', $accessKey, $secretKey, $payloadHash, $amzDate);
expectNoException(function () use ($credentials, $authzServerName, $host, $path, $payloadHash, $amzDate): void {
    $auth = makeAuth($credentials, [], false, 900, 604800, '', true);
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authzServerName,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ], ['SERVER_NAME' => 'internal.local', 'SERVER_PORT' => 80]);
    $auth->authenticate($req);
}, 'host candidate fallback authenticates via SERVER_NAME');

// Default port variant: sign with host:80, request Host without port -> should match
$authzWithPort = signHeaderAuth('GET', $host . ':80', $path, '', $accessKey, $secretKey, $payloadHash, $amzDate);
expectNoException(function () use ($credentials, $authzWithPort, $host, $path, $payloadHash, $amzDate): void {
    $auth = makeAuth($credentials, [], false, 900, 604800, '', true);
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $authzWithPort,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ], ['SERVER_NAME' => $host, 'SERVER_PORT' => 80]);
    $auth->authenticate($req);
}, 'host candidate fallback matches default-port variant');

// ===========================================================================
// Auth debug log
// ===========================================================================

$tmpDir = sys_get_temp_dir() . '/mini-s3-sigv4-test-' . bin2hex(random_bytes(4));
@mkdir($tmpDir, 0777, true);
$logPath = $tmpDir . '/auth-debug.jsonl';

$loggingAuth = makeAuth($credentials, [], false, 900, 604800, $logPath, false);
$badAuthz = signHeaderAuth('GET', 'wrong-host.com', $path, '', $accessKey, $secretKey, $payloadHash, $amzDate);
try {
    $req = makeRequest('GET', $path, '', [
        'host' => $host,
        'authorization' => $badAuthz,
        'x-amz-date' => $amzDate,
        'x-amz-content-sha256' => $payloadHash,
    ]);
    $loggingAuth->authenticate($req);
} catch (AuthException $e) {
    // expected
}

$logContents = is_file($logPath) ? file_get_contents($logPath) : '';
check($logContents !== false && $logContents !== '', 'auth debug log written on signature mismatch');
if ($logContents !== '') {
    $record = json_decode($logContents, true);
    check(is_array($record) && ($record['mode'] ?? '') === 'authorization', 'auth debug log record has correct mode');
    check(is_array($record) && isset($record['attempts']) && count($record['attempts']) >= 1, 'auth debug log records at least one host attempt');
}

// Cleanup
@unlink($logPath);
@rmdir($tmpDir);

// ===========================================================================
// Result
// ===========================================================================

if ($failures > 0) {
    fwrite(STDERR, PHP_EOL . "FAILED: {$failures}/{$tests} tests failed" . PHP_EOL);
    exit(1);
}

echo "[PASS] SigV4Authenticator tests passed ({$tests} assertions)" . PHP_EOL;
