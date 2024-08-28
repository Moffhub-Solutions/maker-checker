<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestRejected
{
    public MakerCheckerRequest $request;

    public function __construct(MakerCheckerRequest $request)
    {
        $this->request = $request;
    }
}
