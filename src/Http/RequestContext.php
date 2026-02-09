<?php

declare(strict_types=1);

namespace MiniS3\Http;

final class RequestContext
{
    private array $headers;
    private array $query;

    public function __construct(
        private readonly string $method,
        private readonly string $requestUri,
        private readonly array $server
    ) {
        $this->headers = $this->buildHeaders($server);
        $this->query = $this->buildQuery($requestUri);
    }

    public static function fromGlobals(): self
    {
        $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return new self($method, $requestUri, $_SERVER);
    }

    public function getMethod(): string
    {
        return strtoupper($this->method);
    }

    public function getRequestUri(): string
    {
        return $this->requestUri;
    }

    public function getPath(): string
    {
        $path = parse_url($this->requestUri, PHP_URL_PATH);
        if ($path === null || $path === false || $path === '') {
            return '/';
        }

        return $path;
    }

    public function getRawQueryString(): string
    {
        $query = parse_url($this->requestUri, PHP_URL_QUERY);

        return is_string($query) ? $query : '';
    }

    public function getQueryParam(string $name): ?string
    {
        if (!array_key_exists($name, $this->query)) {
            return null;
        }

        $value = $this->query[$name];
        if (is_array($value)) {
            return null;
        }

        return (string) $value;
    }

    public function hasQueryParam(string $name): bool
    {
        return array_key_exists($name, $this->query);
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        $normalized = strtolower($name);

        return $this->headers[$normalized] ?? null;
    }

    public function getHost(): string
    {
        $host = $this->getHeader('host');
        if ($host !== null && $host !== '') {
            return $host;
        }

        $serverName = (string) ($this->server['SERVER_NAME'] ?? 'localhost');
        $serverPort = (int) ($this->server['SERVER_PORT'] ?? 80);

        if (($serverPort === 80 || $serverPort === 443) && $serverName !== '') {
            return $serverName;
        }

        return $serverName . ':' . $serverPort;
    }

    public function getScheme(): string
    {
        $forwardedProto = strtolower((string) ($this->getHeader('x-forwarded-proto') ?? ''));
        if ($forwardedProto === 'https') {
            return 'https';
        }

        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return 'https';
        }

        $serverPort = (int) ($this->server['SERVER_PORT'] ?? 80);
        if ($serverPort === 443) {
            return 'https';
        }

        return 'http';
    }

    public function getServerName(): string
    {
        return (string) ($this->server['SERVER_NAME'] ?? 'localhost');
    }

    public function getServerPort(): int
    {
        return (int) ($this->server['SERVER_PORT'] ?? 80);
    }

    private function buildHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($server['CONTENT_TYPE']) && is_string($server['CONTENT_TYPE'])) {
            $headers['content-type'] = $server['CONTENT_TYPE'];
        }

        if (isset($server['CONTENT_LENGTH']) && is_string($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = $server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    private function buildQuery(string $requestUri): array
    {
        $queryString = parse_url($requestUri, PHP_URL_QUERY);
        if (!is_string($queryString) || $queryString === '') {
            return [];
        }

        parse_str($queryString, $query);

        return is_array($query) ? $query : [];
    }
}
