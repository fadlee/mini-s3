<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminAuth
{
    private const AUTH_KEY = 'mini_s3_admin_authenticated';
    private const CSRF_KEY = 'mini_s3_admin_csrf_token';
    private const FLASH_KEY = 'mini_s3_admin_flash';

    public const SESSION_NAME = 'mini_s3_admin';

    public function __construct(
        private readonly string $username,
        private readonly string $passwordHash
    ) {
        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            self::startSession();
        }
    }

    /**
     * Starts the admin session with hardened cookie settings instead of
     * whatever the host's php.ini uses. SameSite=Lax keeps links from other
     * sites working; every state change is still protected by a CSRF token.
     */
    private static function startSession(): void
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        session_name(self::SESSION_NAME);
        session_set_cookie_params(self::cookieParams());
        session_start();
    }

    /**
     * @return array{lifetime: int, path: string, secure: bool, httponly: bool, samesite: string}
     */
    public static function cookieParams(): array
    {
        return [
            'lifetime' => 0,
            'path' => '/_',
            'secure' => self::isHttpsRequest(),
            'httponly' => true,
            'samesite' => 'Lax',
        ];
    }

    public static function isHttpsRequest(): bool
    {
        $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        if ($https !== '' && $https !== 'off') {
            return true;
        }
        if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
            return true;
        }

        return strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0])) === 'https';
    }

    public function isConfigured(): bool
    {
        return $this->passwordHash !== '';
    }

    public function isAuthenticated(): bool
    {
        return (bool) ($_SESSION[self::AUTH_KEY] ?? false);
    }

    public function login(string $username, string $password): bool
    {
        if (!$this->isConfigured() || !hash_equals($this->username, trim($username)) || !password_verify($password, $this->passwordHash)) {
            return false;
        }

        if (PHP_SAPI !== 'cli') {
            session_regenerate_id(true);
        }
        $_SESSION[self::AUTH_KEY] = true;

        return true;
    }

    public function logout(): void
    {
        unset($_SESSION[self::AUTH_KEY], $_SESSION[self::CSRF_KEY], $_SESSION[self::FLASH_KEY]);
        if (PHP_SAPI !== 'cli') {
            session_destroy();
            $params = self::cookieParams();
            setcookie(self::SESSION_NAME, '', [
                'expires' => time() - 3600,
                'path' => $params['path'],
                'secure' => $params['secure'],
                'httponly' => true,
                'samesite' => $params['samesite'],
            ]);
        }
    }

    public function setFlash(string $message): void
    {
        $_SESSION[self::FLASH_KEY] = $message;
    }

    public function consumeFlash(): string
    {
        $message = (string) ($_SESSION[self::FLASH_KEY] ?? '');
        unset($_SESSION[self::FLASH_KEY]);

        return $message;
    }

    public function csrfToken(): string
    {
        $token = (string) ($_SESSION[self::CSRF_KEY] ?? '');
        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $_SESSION[self::CSRF_KEY] = $token;
        }

        return $token;
    }

    public function verifyCsrfToken(string $token): bool
    {
        $expected = (string) ($_SESSION[self::CSRF_KEY] ?? '');

        return $expected !== '' && hash_equals($expected, $token);
    }
}
