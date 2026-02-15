<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Exceptions;

use Exception;

class FreeAgentApiException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?array $responseData = null
    ) {
        parent::__construct($message, $statusCode);
    }

    public static function requestFailed(int $statusCode, ?array $responseData = null): self
    {
        return new self(
            "FreeAgent API request failed with status: {$statusCode}",
            $statusCode,
            $responseData
        );
    }

    public static function rateLimitExceeded(): self
    {
        return new self('FreeAgent API rate limit exceeded', 429);
    }

    public static function authenticationFailed(string $message = 'Authentication failed'): self
    {
        return new self($message, 401);
    }

    public static function networkError(string $message): self
    {
        return new self("Network error: {$message}");
    }
}
