<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Throwable;

class RequestFailed
{
    public function __construct(public MakerCheckerRequest $request, public Throwable $exception) {}
}
