<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use InvalidArgumentException;
use Moffhub\MakerChecker\Enums\RequestType;

class InvalidRequestTypePassed extends InvalidArgumentException
{
    public static function create(RequestType $requestType): self
    {
        $allowedRequestTypes = array_column(RequestType::cases(), 'value');
        $allowedRequestTypesString = implode(', ', $allowedRequestTypes);
        $message = sprintf(
            'The type: %s is not a valid request type. Request type must be one of: %s',
            $requestType->value,
            $allowedRequestTypesString,
        );

        return new self($message);
    }
}
