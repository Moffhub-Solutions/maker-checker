<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use RuntimeException;
use Throwable;

class RequestCouldNotBeProcessed extends RuntimeException
{
    public static function create(string $reason, ?Throwable $exception = null): self
    {
        return new self("Failed to process request: $reason", 0, $exception);
    }
}
