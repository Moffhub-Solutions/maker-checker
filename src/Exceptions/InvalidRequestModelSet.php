<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Exceptions;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Contracts\MakerCheckerRequestInterface;

class InvalidRequestModelSet extends Exception
{
    public static function create(): self
    {
        return new self(
            vsprintf(
                'Invalid value passed for `request_model` in the package configuration. The request model must be a class that extends %s and implements the %s interface.',
                [Model::class, MakerCheckerRequestInterface::class]
            )
        );
    }
}
