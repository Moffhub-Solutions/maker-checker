<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MakerCheckerRequest $request,
        public readonly Model $maker,
        public readonly Model $checker,
        public readonly ?string $rejectionReason,
    ) {}

    public static function fromRequest(MakerCheckerRequest $request, Model $checker, ?string $remarks): self
    {
        return new self(
            request: $request,
            maker: $request->maker,
            checker: $checker,
            rejectionReason: $remarks,
        );
    }
}
