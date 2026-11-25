<?php

declare(strict_types=1);

namespace MiniS3\Admin;

/**
 * Limits failed admin logins per client IP: after MAX_FAILURES failures
 * within WINDOW_SECONDS the IP is locked out for WINDOW_SECONDS.
 *
 * State lives in a small JSON file (no database). IPs are stored hashed.
 * Behind a reverse proxy every client shares the proxy's REMOTE_ADDR, so the
 * limit then applies to all clients together.
 */
final class LoginThrottle
{
    public const MAX_FAILURES = 5;
    public const WINDOW_SECONDS = 900;

    private readonly int $now;

    public function __construct(private readonly string $path, ?int $now = null)
    {
        $this->now = $now ?? time();
    }

    /**
     * Seconds until the client may try again; 0 when a login attempt is allowed.
     */
    public function retryAfter(string $ip): int
    {
        $entry = $this->read()[$this->key($ip)] ?? null;
        if ($entry === null) {
            return 0;
        }

        return max(0, (int) ($entry['locked_until'] ?? 0) - $this->now);
    }

    public function recordFailure(string $ip): void
    {
        $this->update(function (array $entries) use ($ip): array {
            $key = $this->key($ip);
            $entry = $entries[$key] ?? null;
            if ($entry === null || $this->now - (int) $entry['first'] > self::WINDOW_SECONDS) {
                $entry = ['first' => $this->now, 'count' => 0, 'locked_until' => 0];
            }
            $entry['count']++;
            if ($entry['count'] >= self::MAX_FAILURES) {
                $entry['locked_until'] = $this->now + self::WINDOW_SECONDS;
            }
            $entries[$key] = $entry;

            return $entries;
        });
    }

    public function reset(string $ip): void
    {
        $this->update(function (array $entries) use ($ip): array {
            unset($entries[$this->key($ip)]);

            return $entries;
        });
    }

    private function key(string $ip): string
    {
        return hash('sha256', $ip);
    }

    private function read(): array
    {
        $raw = @file_get_contents($this->path);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function update(callable $change): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) || !is_writable($dir)) {
            return; // Throttling is best effort; never block logins on a read-only disk.
        }
        $handle = @fopen($this->path, 'c+');
        if ($handle === false) {
            return;
        }
        try {
            flock($handle, LOCK_EX);
            $decoded = json_decode((string) stream_get_contents($handle), true);
            $entries = $change(is_array($decoded) ? $decoded : []);
            $entries = array_filter(
                $entries,
                fn(array $entry): bool => (int) $entry['locked_until'] > $this->now
                    || $this->now - (int) $entry['first'] <= self::WINDOW_SECONDS
            );
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode($entries));
            fflush($handle);
            @chmod($this->path, 0600);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
