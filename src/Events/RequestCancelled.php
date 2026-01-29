<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(public MakerCheckerRequest $request) {}
}
