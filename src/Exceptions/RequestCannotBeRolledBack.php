<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use Exception;

class RequestCannotBeRolledBack extends Exception
{
    public static function create(string $reason): self
    {
        return new self($reason);
    }
}
