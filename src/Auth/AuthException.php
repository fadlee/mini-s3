<?php

declare(strict_types=1);

namespace MiniS3\Auth;

use RuntimeException;

final class AuthException extends RuntimeException
{
    public function __construct(
        private readonly string $s3Code,
        string $message,
        private readonly int $httpStatus = 401
    ) {
        parent::__construct($message);
    }

    public function getS3Code(): string
    {
        return $this->s3Code;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
