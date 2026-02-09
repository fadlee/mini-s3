<?php

declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php sigv4.php <auth|presign> ...\n");
    exit(1);
}

$mode = $argv[1];

if ($mode === 'auth') {
    // auth METHOD URL ACCESS_KEY SECRET_KEY PAYLOAD_HASH [AMZ_DATE] [REGION]
    if ($argc < 7) {
        fwrite(STDERR, "Usage: php sigv4.php auth METHOD URL ACCESS_KEY SECRET_KEY PAYLOAD_HASH [AMZ_DATE] [REGION]\n");
        exit(1);
    }

    $method = strtoupper($argv[2]);
    $url = $argv[3];
    $accessKey = $argv[4];
    $secretKey = $argv[5];
    $payloadHash = $argv[6];
    $amzDate = $argv[7] ?? gmdate('Ymd\\THis\\Z');
    $region = $argv[8] ?? 'us-east-1';

    $parts = parse_url($url);
    if ($parts === false) {
        fwrite(STDERR, "Invalid URL\n");
        exit(1);
    }

    $host = $parts['host'] ?? 'localhost';
    if (isset($parts['port'])) {
        $host .= ':' . $parts['port'];
    }

    $path = $parts['path'] ?? '/';
    $query = $parts['query'] ?? '';

    $date = substr($amzDate, 0, 8);
    $credentialScope = $date . '/' . $region . '/s3/aws4_request';

    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalHeaders = 'host:' . normalizeHeaderValue($host) . "\n"
        . 'x-amz-content-sha256:' . normalizeHeaderValue($payloadHash) . "\n"
        . 'x-amz-date:' . normalizeHeaderValue($amzDate) . "\n";

    $canonicalRequest = implode("\n", [
        $method,
        canonicalUri($path),
        canonicalQueryString($query, []),
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $signature = calculateSignature($secretKey, $date, $region, 's3', $stringToSign);

    $authorization = 'AWS4-HMAC-SHA256 '
        . 'Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders
        . ', Signature=' . $signature;

    echo 'x-amz-date=' . $amzDate . "\n";
    echo 'authorization=' . $authorization . "\n";
    exit(0);
}

if ($mode === 'presign') {
    // presign METHOD URL ACCESS_KEY SECRET_KEY EXPIRES OFFSET_SECONDS [REGION]
    if ($argc < 8) {
        fwrite(STDERR, "Usage: php sigv4.php presign METHOD URL ACCESS_KEY SECRET_KEY EXPIRES OFFSET_SECONDS [REGION]\n");
        exit(1);
    }

    $method = strtoupper($argv[2]);
    $url = $argv[3];
    $accessKey = $argv[4];
    $secretKey = $argv[5];
    $expires = (int) $argv[6];
    $offsetSeconds = (int) $argv[7];
    $region = $argv[8] ?? 'us-east-1';

    if ($expires < 1) {
        fwrite(STDERR, "Expires must be >= 1\n");
        exit(1);
    }

    $parts = parse_url($url);
    if ($parts === false) {
        fwrite(STDERR, "Invalid URL\n");
        exit(1);
    }

    $scheme = $parts['scheme'] ?? 'http';
    $host = $parts['host'] ?? 'localhost';
    $port = $parts['port'] ?? null;
    $path = $parts['path'] ?? '/';
    $rawQuery = $parts['query'] ?? '';

    $hostHeader = $host;
    if ($port !== null) {
        $hostHeader .= ':' . $port;
    }

    $amzDate = gmdate('Ymd\\THis\\Z', time() + $offsetSeconds);
    $date = substr($amzDate, 0, 8);
    $credentialScope = $date . '/' . $region . '/s3/aws4_request';

    $queryPairs = parseRawQueryToPairs($rawQuery);
    $queryPairs[] = ['X-Amz-Algorithm', 'AWS4-HMAC-SHA256'];
    $queryPairs[] = ['X-Amz-Credential', $accessKey . '/' . $credentialScope];
    $queryPairs[] = ['X-Amz-Date', $amzDate];
    $queryPairs[] = ['X-Amz-Expires', (string) $expires];
    $queryPairs[] = ['X-Amz-SignedHeaders', 'host'];

    $canonicalQuery = canonicalQueryStringFromPairs($queryPairs, ['X-Amz-Signature']);
    $canonicalRequest = implode("\n", [
        $method,
        canonicalUri($path),
        $canonicalQuery,
        'host:' . normalizeHeaderValue($hostHeader) . "\n",
        'host',
        'UNSIGNED-PAYLOAD',
    ]);

    $stringToSign = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $signature = calculateSignature($secretKey, $date, $region, 's3', $stringToSign);

    $queryPairs[] = ['X-Amz-Signature', $signature];
    $finalQuery = canonicalQueryStringFromPairs($queryPairs, []);

    $fullHost = $host;
    if ($port !== null) {
        $fullHost .= ':' . $port;
    }

    echo $scheme . '://' . $fullHost . canonicalUri($path) . '?' . $finalQuery . "\n";
    exit(0);
}

fwrite(STDERR, "Unknown mode: {$mode}\n");
exit(1);

function calculateSignature(string $secretKey, string $date, string $region, string $service, string $stringToSign): string
{
    $kDate = hash_hmac('sha256', $date, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', $service, $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

    return hash_hmac('sha256', $stringToSign, $kSigning);
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
    return canonicalQueryStringFromPairs(parseRawQueryToPairs($rawQuery), $excludedKeys);
}

function canonicalQueryStringFromPairs(array $pairs, array $excludedKeys): string
{
    $exclude = [];
    foreach ($excludedKeys as $excludedKey) {
        $exclude[$excludedKey] = true;
    }

    $encodedPairs = [];
    foreach ($pairs as $pair) {
        $key = (string) ($pair[0] ?? '');
        $value = (string) ($pair[1] ?? '');

        if (isset($exclude[$key])) {
            continue;
        }

        $encodedPairs[] = [awsPercentEncode($key), awsPercentEncode($value)];
    }

    usort(
        $encodedPairs,
        static function (array $a, array $b): int {
            if ($a[0] === $b[0]) {
                return $a[1] <=> $b[1];
            }
            return $a[0] <=> $b[0];
        }
    );

    $parts = [];
    foreach ($encodedPairs as [$key, $value]) {
        $parts[] = $key . '=' . $value;
    }

    return implode('&', $parts);
}

function parseRawQueryToPairs(string $rawQuery): array
{
    if ($rawQuery === '') {
        return [];
    }

    $pairs = [];
    foreach (explode('&', $rawQuery) as $pair) {
        if ($pair === '') {
            continue;
        }

        $parts = explode('=', $pair, 2);
        $rawKey = $parts[0];
        $rawValue = $parts[1] ?? '';

        $pairs[] = [rawurldecode($rawKey), rawurldecode($rawValue)];
    }

    return $pairs;
}

function normalizeHeaderValue(string $value): string
{
    return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
}

function awsPercentEncode(string $value): string
{
    return str_replace('%7E', '~', rawurlencode($value));
}
