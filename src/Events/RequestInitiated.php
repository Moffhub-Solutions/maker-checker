<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MakerCheckerRequest $request,
        public readonly Model $maker,
        public readonly string $subjectType,
        public readonly string $actionType,
        public readonly array $payloadSummary,
    ) {}

    public static function fromRequest(MakerCheckerRequest $request): self
    {
        return new self(
            request: $request,
            maker: $request->maker,
            subjectType: $request->subject_type ?? '',
            actionType: $request->type->value,
            payloadSummary: $request->payload ?? [],
        );
    }
}
