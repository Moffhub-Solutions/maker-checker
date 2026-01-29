<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestInitiated
{
    public function __construct(public MakerCheckerRequest $request) {}
}
