<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestFailed
{
    use Dispatchable, SerializesModels;

    public readonly string $errorMessage;

    public function __construct(
        public readonly MakerCheckerRequest $request,
        public readonly \Throwable $exception,
    ) {
        $this->errorMessage = $exception->getMessage();
    }
}
