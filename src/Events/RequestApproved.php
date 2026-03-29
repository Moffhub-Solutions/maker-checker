<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class RequestApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly MakerCheckerRequest $request,
        public readonly Model $maker,
        public readonly Model $checker,
        public readonly ?Model $subject,
        public readonly string $actionType,
        public readonly int $approvalCount,
        public readonly int $requiredCount,
    ) {}

    public static function fromRequest(MakerCheckerRequest $request, Model $checker): self
    {
        $requiredApprovals = $request->required_approvals ?? [];
        $requiredCount = 0;

        if (isset($requiredApprovals['roles'])) {
            $requiredCount += (int) array_sum($requiredApprovals['roles']);
            $requiredCount += count($requiredApprovals['users'] ?? []);
        } elseif (!empty($requiredApprovals)) {
            $requiredCount = (int) array_sum($requiredApprovals);
        } else {
            $requiredCount = (int) config('maker-checker.default_approval_count', 1);
        }

        return new self(
            request: $request,
            maker: $request->maker,
            checker: $checker,
            subject: $request->subject,
            actionType: $request->type->value,
            approvalCount: $request->getApprovalCount(),
            requiredCount: $requiredCount,
        );
    }
}
