<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use RuntimeException;

class UnauthorizedApproverException extends RuntimeException
{
    public static function create(string $reason): self
    {
        return new self("Unauthorized approver: $reason");
    }
}
