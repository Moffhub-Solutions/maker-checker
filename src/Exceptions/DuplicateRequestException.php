<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use Moffhub\MakerChecker\Enums\RequestType;
use RuntimeException;

class DuplicateRequestException extends RuntimeException
{
    public static function create(RequestType $requestType): self
    {
        return new self("A pending request already exists to {$requestType->display()} the provided resource.");
    }
}
