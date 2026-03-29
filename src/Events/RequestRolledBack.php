<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestRolledBack
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MakerCheckerRequest $request,
        public readonly ?string $rollbackReason,
    ) {}

    public static function fromRequest(MakerCheckerRequest $request, ?string $remarks): self
    {
        return new self(
            request: $request,
            rollbackReason: $remarks,
        );
    }
}
