<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestApproved
{
    public function __construct(public MakerCheckerRequest $request) {}
}
