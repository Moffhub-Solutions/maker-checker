<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use RuntimeException;
use Throwable;

class FulfillmentException extends RuntimeException
{
    public static function invalidPayload(string $expectedType): self
    {
        return new self("Payload must be {$expectedType}.");
    }

    public static function invalidExecutable(string $reason): self
    {
        return new self("Executable could not be resolved: {$reason}");
    }

    public static function create(string $reason, ?Throwable $previous = null): self
    {
        return new self("Failed to fulfill request: {$reason}", 0, $previous);
    }
}
