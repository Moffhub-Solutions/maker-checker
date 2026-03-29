<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestExpired
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MakerCheckerRequest $request,
        public readonly Model $maker,
        public readonly Carbon $expiredAt,
        public readonly int $pendingApproverCount,
    ) {}

    public static function fromRequest(MakerCheckerRequest $request): self
    {
        $pendingRoles = $request->getPendingRoles();
        $pendingUsers = $request->getPendingUsers();
        $pendingCount = (int) array_sum($pendingRoles) + count($pendingUsers);

        return new self(
            request: $request,
            maker: $request->maker,
            expiredAt: Carbon::now(),
            pendingApproverCount: $pendingCount,
        );
    }
}
