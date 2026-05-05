<?php

declare(strict_types=1);

namespace MiniS3\Admin;

final class AdminAuth
{
    private const AUTH_KEY = 'mini_s3_admin_authenticated';
    private const CSRF_KEY = 'mini_s3_admin_csrf_token';
    private const FLASH_KEY = 'mini_s3_admin_flash';

    public function __construct(private readonly string $passwordHash)
    {
        if (session_status() !== PHP_SESSION_ACTIVE && PHP_SAPI !== 'cli') {
            session_start();
        }
    }

    public function isConfigured(): bool
    {
        return $this->passwordHash !== '';
    }

    public function isAuthenticated(): bool
    {
        return (bool) ($_SESSION[self::AUTH_KEY] ?? false);
    }

    public function login(string $password): bool
    {
        if (!$this->isConfigured() || !password_verify($password, $this->passwordHash)) {
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
