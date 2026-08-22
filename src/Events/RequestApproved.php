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
        $defaultCount = (int) config('maker-checker.default_approval_count', 1);

        if ($requiredApprovals === []) {
            $requiredCount = $defaultCount;
        } else {
            $isNewFormat = isset($requiredApprovals['roles'])
                || isset($requiredApprovals['users'])
                || isset($requiredApprovals['mode']);

            if ($isNewFormat) {
                // 'any' mode is satisfied by a single approval; otherwise all
                // role thresholds plus all required users must be met.
                if (($requiredApprovals['mode'] ?? 'all') === 'any') {
                    $requiredCount = 1;
                } else {
                    $roles = $requiredApprovals['roles'] ?? [];
                    $users = $requiredApprovals['users'] ?? [];
                    $userCount = is_array($users) ? count($users) : 0;
                    $requiredCount = self::sumCounts($roles) + $userCount;
                }
            } else {
                // Legacy format: ['role' => count]
                $requiredCount = self::sumCounts($requiredApprovals);
            }

            if ($requiredCount < 1) {
                $requiredCount = $defaultCount;
            }
        }

        return self::build($request, $checker, $requiredCount);
    }

    /**
     * Sum only the numeric values of a role => count map, ignoring any
     * non-numeric entries (e.g. nested arrays or flags).
     *
     * @param  mixed  $counts
     */
    private static function sumCounts($counts): int
    {
        if (!is_array($counts)) {
            return 0;
        }

        $total = 0;

        foreach ($counts as $value) {
            if (is_numeric($value)) {
                $total += (int) $value;
            }
        }

        return $total;
    }

    private static function build(MakerCheckerRequest $request, Model $checker, int $requiredCount): self
    {
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
