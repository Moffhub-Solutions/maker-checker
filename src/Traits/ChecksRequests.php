<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Traits;

use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

trait ChecksRequests
{
    public function approve(MakerCheckerRequest $request, ?string $remarks = null): MakerCheckerRequest
    {
        return MakerChecker::approve($request, $this, $this->role, $remarks);
    }

    public function reject(MakerCheckerRequest $request, ?string $remarks = null): MakerCheckerRequest
    {
        return MakerChecker::reject($request, $this, $remarks);
    }
}
