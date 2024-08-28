<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use RuntimeException;

class ModelCannotCheckRequests extends RuntimeException
{
    public static function create(string $modelClass): self
    {
        return new self("Cannot approve/decline request: model: $modelClass is not allowed to check requests.");
    }
}
