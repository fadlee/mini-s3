<?php

declare(strict_types=1);

namespace MiniS3\Auth;

use DateTimeImmutable;
use DateTimeZone;
use MiniS3\Http\RequestContext;

final class SigV4Authenticator
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    private array $credentials;
    private array $allowedAccessKeys;

    public function __construct(
        array $credentials,
        array $allowedAccessKeys,
        private readonly bool $allowLegacyAccessKeyOnly,
        private readonly int $clockSkewSeconds,
        private readonly int $maxPresignExpires,
        private readonly string $authDebugLogPath = ''
    ) {
        $normalizedCredentials = [];
        foreach ($credentials as $accessKey => $secretKey) {
            $normalizedCredentials[(string) $accessKey] = (string) $secretKey;
        }
        $this->credentials = $normalizedCredentials;

        $this->allowedAccessKeys = [];
        foreach ($allowedAccessKeys as $accessKey) {
            $this->allowedAccessKeys[(string) $accessKey] = true;
        }
    }

    public function authenticate(RequestContext $request): void
    {
        if ($this->isPresignedRequest($request)) {
            $this->authenticatePresignedRequest($request);
            return;
        }

        $authorization = $request->getHeader('authorization');
        if ($authorization !== null && str_starts_with($authorization, self::ALGORITHM)) {
            $this->authenticateAuthorizationHeaderRequest($request, $authorization);
            return;
        }

        if ($this->allowLegacyAccessKeyOnly) {
            $legacyAccessKey = $this->extractAccessKeyId($request);
            if ($legacyAccessKey !== null && isset($this->allowedAccessKeys[$legacyAccessKey])) {
                return;
            }
        }

        throw new AuthException('AccessDenied', 'Missing or unsupported authentication credentials');
    }

    private function authenticateAuthorizationHeaderRequest(RequestContext $request, string $authorization): void
    {
        $authParams = $this->parseAuthorizationHeader($authorization);

        $credentialScope = $this->parseCredential($authParams['Credential'] ?? '');
        $accessKeyId = $credentialScope['accessKeyId'];
        $secretKey = $this->credentials[$accessKeyId] ?? null;
        if ($secretKey === null) {
            throw new AuthException('InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.');
        }

        $amzDate = $request->getHeader('x-amz-date');
        if ($amzDate === null) {
            throw new AuthException('AccessDenied', 'Missing required header x-amz-date');
        }

        $requestTime = $this->parseAmzDate($amzDate);
        $this->validateHeaderTimestamp($requestTime);

        $payloadHash = $request->getHeader('x-amz-content-sha256');
        if ($payloadHash === null || $payloadHash === '') {
            throw new AuthException('AccessDenied', 'Missing required header x-amz-content-sha256');
        }

        $signedHeaders = $this->parseSignedHeaders($authParams['SignedHeaders'] ?? '');
        $signature = strtolower((string) ($authParams['Signature'] ?? ''));
        if ($signature === '') {
            throw new AuthException('AccessDenied', 'Missing Signature in Authorization header');
        }

        $scope = sprintf(
            '%s/%s/%s/aws4_request',
            $credentialScope['date'],
            $credentialScope['region'],
            $credentialScope['service']
        );
        $attempts = [];
        if (!$this->signatureMatchesAnyHostCandidate(
            $request,
            $signedHeaders,
            $payloadHash,
            true,
            $amzDate,
            $scope,
            $credentialScope,
            $secretKey,
            $signature,
            $attempts
        )) {
            $this->logSignatureMismatch('authorization', $request, $signedHeaders, $signature, $attempts);
            throw new AuthException('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
        }
    }

    private function authenticatePresignedRequest(RequestContext $request): void
    {
        $algorithm = $request->getQueryParam('X-Amz-Algorithm');
        if ($algorithm !== self::ALGORITHM) {
            throw new AuthException('AuthorizationQueryParametersError', 'Unsupported X-Amz-Algorithm in query string');
        }

        $credentialScope = $this->parseCredential((string) $request->getQueryParam('X-Amz-Credential'));
        $accessKeyId = $credentialScope['accessKeyId'];
        $secretKey = $this->credentials[$accessKeyId] ?? null;
        if ($secretKey === null) {
            throw new AuthException('InvalidAccessKeyId', 'The AWS Access Key Id you provided does not exist in our records.');
        }

        $amzDate = (string) $request->getQueryParam('X-Amz-Date');
        if ($amzDate === '') {
            throw new AuthException('AuthorizationQueryParametersError', 'Missing X-Amz-Date query parameter');
        }

        $requestTime = $this->parseAmzDate($amzDate);

        $expiresRaw = $request->getQueryParam('X-Amz-Expires');
        if ($expiresRaw === null || !ctype_digit($expiresRaw)) {
            throw new AuthException('AuthorizationQueryParametersError', 'Invalid X-Amz-Expires query parameter');
        }

        $expires = (int) $expiresRaw;
        if ($expires < 1 || $expires > $this->maxPresignExpires) {
            throw new AuthException('AuthorizationQueryParametersError', 'X-Amz-Expires out of allowed range');
        }

        $this->validatePresignedTimestamp($requestTime, $expires);

        $signedHeaders = $this->parseSignedHeaders((string) $request->getQueryParam('X-Amz-SignedHeaders'));
        $providedSignature = strtolower((string) $request->getQueryParam('X-Amz-Signature'));
        if ($providedSignature === '') {
            throw new AuthException('AuthorizationQueryParametersError', 'Missing X-Amz-Signature query parameter');
        }

        $scope = sprintf(
            '%s/%s/%s/aws4_request',
            $credentialScope['date'],
            $credentialScope['region'],
            $credentialScope['service']
        );
        $attempts = [];
        if (!$this->signatureMatchesAnyHostCandidate(
            $request,
            $signedHeaders,
            'UNSIGNED-PAYLOAD',
            false,
            $amzDate,
            $scope,
            $credentialScope,
            $secretKey,
            $providedSignature,
            $attempts
        )) {
            $this->logSignatureMismatch('presign', $request, $signedHeaders, $providedSignature, $attempts);
            throw new AuthException('SignatureDoesNotMatch', 'The request signature we calculated does not match the signature you provided.');
        }
    }

    private function parseAuthorizationHeader(string $authorization): array
    {
        if (!str_starts_with($authorization, self::ALGORITHM . ' ')) {
            throw new AuthException('AccessDenied', 'Authorization algorithm is not supported');
        }

        $paramsRaw = substr($authorization, strlen(self::ALGORITHM) + 1);
        $parts = array_filter(array_map('trim', explode(',', $paramsRaw)), static fn(string $value): bool => $value !== '');

        $params = [];
        foreach ($parts as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) !== 2) {
                throw new AuthException('AccessDenied', 'Malformed Authorization header');
            }

            $params[$pair[0]] = $pair[1];
        }

        foreach (['Credential', 'SignedHeaders', 'Signature'] as $required) {
            if (!isset($params[$required]) || trim($params[$required]) === '') {
                throw new AuthException('AccessDenied', 'Malformed Authorization header: missing ' . $required);
            }
        }

        return $params;
    }

    private function parseCredential(string $credential): array
    {
        if ($credential === '') {
            throw new AuthException('AuthorizationQueryParametersError', 'Missing Credential scope');
        }

        $parts = explode('/', $credential);
        if (count($parts) !== 5) {
            throw new AuthException('AuthorizationQueryParametersError', 'Credential scope format is invalid');
        }

        [$accessKeyId, $date, $region, $service, $terminal] = $parts;
        if ($accessKeyId === '' || $date === '' || $region === '' || $service === '' || $terminal === '') {
            throw new AuthException('AuthorizationQueryParametersError', 'Credential scope is incomplete');
        }

        if (!preg_match('/^\d{8}$/', $date)) {
            throw new AuthException('AuthorizationQueryParametersError', 'Credential date is invalid');
        }

        if ($service !== 's3' || $terminal !== 'aws4_request') {
            throw new AuthException('AuthorizationQueryParametersError', 'Credential scope service must be s3/aws4_request');
        }

        return [
            'accessKeyId' => $accessKeyId,
            'date' => $date,
            'region' => $region,
            'service' => $service,
        ];
    }

    private function parseSignedHeaders(string $signedHeaders): array
    {
        $items = array_filter(explode(';', strtolower(trim($signedHeaders))), static fn(string $value): bool => $value !== '');
        if ($items === []) {
            throw new AuthException('AuthorizationQueryParametersError', 'SignedHeaders must not be empty');
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!preg_match('/^[a-z0-9-]+$/', $item)) {
                throw new AuthException('AuthorizationQueryParametersError', 'SignedHeaders contains invalid header names');
            }
            $normalized[] = $item;
        }

        $unique = array_values(array_unique($normalized));
        sort($unique, SORT_STRING);

        if ($unique !== $normalized) {
            throw new AuthException('AuthorizationQueryParametersError', 'SignedHeaders must be lowercase, unique, and sorted');
        }

        return $unique;
    }

    private function parseAmzDate(string $amzDate): DateTimeImmutable
    {
        $time = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));
        if ($time === false) {
            throw new AuthException('AccessDenied', 'Invalid x-amz-date format');
        }

        return $time;
    }

    private function validateHeaderTimestamp(DateTimeImmutable $requestTime): void
    {
        $now = time();
        $timestamp = $requestTime->getTimestamp();
        if (abs($now - $timestamp) > $this->clockSkewSeconds) {
            throw new AuthException('RequestTimeTooSkewed', 'Request timestamp is outside allowed clock skew');
        }
    }

    private function validatePresignedTimestamp(DateTimeImmutable $requestTime, int $expires): void
    {
        $now = time();
        $timestamp = $requestTime->getTimestamp();

        if ($timestamp > ($now + $this->clockSkewSeconds)) {
            throw new AuthException('RequestTimeTooSkewed', 'Request timestamp is too far in the future');
        }

        if ($now > ($timestamp + $expires)) {
            throw new AuthException('ExpiredToken', 'Request has expired');
        }
    }

    private function buildCanonicalRequest(
        RequestContext $request,
        array $signedHeaders,
        string $payloadHash,
        bool $includeAllQueryParams,
        ?string $hostOverride = null
    ): string {
        $canonicalUri = $this->buildCanonicalUri($request->getPath());
        $canonicalQuery = $this->buildCanonicalQueryString(
            $request->getRawQueryString(),
            $includeAllQueryParams ? [] : ['X-Amz-Signature']
        );

        [$canonicalHeaders, $signedHeadersLine] = $this->buildCanonicalHeaders($request, $signedHeaders, $hostOverride);

        return implode("\n", [
            $request->getMethod(),
            $canonicalUri,
            $canonicalQuery,
            $canonicalHeaders,
            $signedHeadersLine,
            $payloadHash,
        ]);
    }

    private function buildCanonicalHeaders(RequestContext $request, array $signedHeaders, ?string $hostOverride = null): array
    {
        $headers = [];
        foreach ($signedHeaders as $headerName) {
            $value = $headerName === 'host'
                ? ($hostOverride ?? $request->getHost())
                : $request->getHeader($headerName);
            if ($value === null) {
                throw new AuthException('AccessDenied', 'Signed header is missing: ' . $headerName);
            }

            $headers[$headerName] = $this->normalizeHeaderValue($value);
        }

        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . $value . "\n";
        }

        $signedHeadersLine = implode(';', array_keys($headers));

        return [$canonicalHeaders, $signedHeadersLine];
    }

    private function buildCanonicalUri(string $path): string
    {
        $segments = explode('/', $path);
        $encodedSegments = [];
        foreach ($segments as $segment) {
            $encodedSegments[] = $this->awsPercentEncode(rawurldecode($segment));
        }

        $canonicalUri = implode('/', $encodedSegments);
        if ($canonicalUri === '') {
            return '/';
        }

        if ($canonicalUri[0] !== '/') {
            $canonicalUri = '/' . $canonicalUri;
        }

        return $canonicalUri;
    }

    private function buildCanonicalQueryString(string $rawQuery, array $excludedKeys): string
    {
        if ($rawQuery === '') {
            return '';
        }

        $excludeMap = [];
        foreach ($excludedKeys as $excludedKey) {
            $excludeMap[$excludedKey] = true;
        }

        $pairs = [];
        foreach (explode('&', $rawQuery) as $pair) {
            if ($pair === '') {
                continue;
            }

            $parts = explode('=', $pair, 2);
            $rawKey = $parts[0];
            $rawValue = $parts[1] ?? '';

            $decodedKey = rawurldecode($rawKey);
            $decodedValue = rawurldecode($rawValue);

            if (isset($excludeMap[$decodedKey])) {
                continue;
            }

            $pairs[] = [
                $this->awsPercentEncode($decodedKey),
                $this->awsPercentEncode($decodedValue),
            ];
        }

        usort(
            $pairs,
            static function (array $a, array $b): int {
                if ($a[0] === $b[0]) {
                    return $a[1] <=> $b[1];
                }

                return $a[0] <=> $b[0];
            }
        );

        $canonicalParts = [];
        foreach ($pairs as [$key, $value]) {
            $canonicalParts[] = $key . '=' . $value;
        }

        return implode('&', $canonicalParts);
    }

    private function buildStringToSign(string $amzDate, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
    }

    private function calculateSignature(string $secretKey, array $credentialScope, string $stringToSign): string
    {
        $kDate = hash_hmac('sha256', $credentialScope['date'], 'AWS4' . $secretKey, true);
        $kRegion = hash_hmac('sha256', $credentialScope['region'], $kDate, true);
        $kService = hash_hmac('sha256', $credentialScope['service'], $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    private function normalizeHeaderValue(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? trim($value);
    }

    private function awsPercentEncode(string $value): string
    {
        return str_replace('%7E', '~', rawurlencode($value));
    }

    private function isPresignedRequest(RequestContext $request): bool
    {
        return $request->hasQueryParam('X-Amz-Algorithm')
            || $request->hasQueryParam('X-Amz-Credential')
            || $request->hasQueryParam('X-Amz-Signature');
    }

    private function signatureMatchesAnyHostCandidate(
        RequestContext $request,
        array $signedHeaders,
        string $payloadHash,
        bool $includeAllQueryParams,
        string $amzDate,
        string $scope,
        array $credentialScope,
        string $secretKey,
        string $providedSignature,
        array &$attempts = []
    ): bool {
        $attempts = [];
        $hostCandidates = $this->hostCandidates($request, in_array('host', $signedHeaders, true));

        foreach ($hostCandidates as $hostCandidate) {
            $canonicalRequest = $this->buildCanonicalRequest(
                $request,
                $signedHeaders,
                $payloadHash,
                $includeAllQueryParams,
                $hostCandidate
            );
            $stringToSign = $this->buildStringToSign($amzDate, $scope, $canonicalRequest);
            $expectedSignature = $this->calculateSignature($secretKey, $credentialScope, $stringToSign);
            $attempts[] = [
                'host' => $hostCandidate,
                'expected_signature' => $expectedSignature,
                'canonical_request_sha256' => hash('sha256', $canonicalRequest),
                'canonical_request' => $canonicalRequest,
                'string_to_sign' => $stringToSign,
            ];

            if (hash_equals($expectedSignature, $providedSignature)) {
                return true;
            }
        }

        return false;
    }

    private function hostCandidates(RequestContext $request, bool $hostSigned): array
    {
        if (!$hostSigned) {
            return [null];
        }

        $rawHosts = [trim($request->getHost())];
        $forwardedHost = $request->getHeader('x-forwarded-host');
        if ($forwardedHost !== null && $forwardedHost !== '') {
            $parts = explode(',', $forwardedHost);
            if ($parts !== []) {
                $rawHosts[] = trim($parts[0]);
            }
        }

        $serverName = trim($request->getServerName());
        if ($serverName !== '') {
            $rawHosts[] = $serverName;
            $rawHosts[] = $serverName . ':' . $request->getServerPort();
        }

        $scheme = $request->getScheme();
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $candidates = [];

        foreach ($rawHosts as $rawHost) {
            $host = strtolower(trim($rawHost));
            if ($host === '') {
                continue;
            }

            $candidates[$host] = true;

            if (str_contains($host, ':')) {
                [$baseHost, $port] = explode(':', $host, 2);
                if ($baseHost !== '' && ctype_digit($port) && (int) $port === $defaultPort) {
                    $candidates[$baseHost] = true;
                }
            } else {
                $candidates[$host . ':' . $defaultPort] = true;
            }
        }

        return array_keys($candidates);
    }

    private function logSignatureMismatch(
        string $mode,
        RequestContext $request,
        array $signedHeaders,
        string $providedSignature,
        array $attempts
    ): void {
        if ($this->authDebugLogPath === '') {
            return;
        }

        $directory = dirname($this->authDebugLogPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $record = [
            'timestamp' => gmdate('c'),
            'mode' => $mode,
            'method' => $request->getMethod(),
            'uri' => $request->getRequestUri(),
            'host' => $request->getHost(),
            'server_name' => $request->getServerName(),
            'server_port' => $request->getServerPort(),
            'signed_headers' => $signedHeaders,
            'provided_signature' => $providedSignature,
            'request_headers' => $request->getHeaders(),
            'attempts' => $attempts,
        ];

        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }

        @file_put_contents($this->authDebugLogPath, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function extractAccessKeyId(RequestContext $request): ?string
    {
        $authorization = (string) ($request->getHeader('authorization') ?? '');
        if (preg_match('/Credential=([^\/]+)\//', $authorization, $matches)) {
            return $matches[1];
        }

        $credential = (string) ($request->getQueryParam('X-Amz-Credential') ?? '');
        if ($credential !== '') {
            $parts = explode('/', $credential);
            if ($parts[0] !== '') {
                return $parts[0];
            }
        }

        return null;
    }
}
