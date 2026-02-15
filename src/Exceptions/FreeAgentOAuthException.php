<?php

declare(strict_types=1);

namespace Zynqa\FilamentFreeAgent\Exceptions;

use Exception;

class FreeAgentOAuthException extends Exception
{
    public static function noTokenAvailable(): self
    {
        return new self('No valid FreeAgent OAuth token available for this user');
    }

    public static function tokenExpired(): self
    {
        return new self('FreeAgent OAuth token has expired');
    }

    public static function refreshFailed(string $message): self
    {
        return new self("Failed to refresh FreeAgent OAuth token: {$message}");
    }

    public static function authorizationFailed(string $message): self
    {
        return new self("FreeAgent OAuth authorization failed: {$message}");
    }

    public static function invalidCallback(string $message): self
    {
        return new self("Invalid OAuth callback: {$message}");
    }
}
