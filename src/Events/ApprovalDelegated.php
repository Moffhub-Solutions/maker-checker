<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Moffhub\MakerChecker\Models\MakerCheckerDelegation;

class ApprovalDelegated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MakerCheckerDelegation $delegation,
        public readonly Model $delegator,
        public readonly Model $delegatee,
        public readonly ?string $scope,
        public readonly ?Carbon $expiresAt,
    ) {}

    public static function fromDelegation(MakerCheckerDelegation $delegation): self
    {
        return new self(
            delegation: $delegation,
            delegator: $delegation->delegator,
            delegatee: $delegation->delegate,
            scope: $delegation->scope,
            expiresAt: $delegation->expires_at,
        );
    }
}
