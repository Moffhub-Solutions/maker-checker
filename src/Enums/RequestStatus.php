<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Enums;

enum RequestStatus: string
{
    case APPROVED = 'approved';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case FAILED = 'failed';
    case PARTIALLY_APPROVED = 'partially_approved';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case REJECTED = 'rejected';

    /**
     * Get all statuses that represent a finalized/terminal state.
     *
     * @return array<self>
     */
    public static function getFinalizedStatuses(): array
    {
        return [self::APPROVED, self::REJECTED, self::EXPIRED, self::FAILED, self::CANCELLED];
    }

    /**
     * Get all statuses that can be acted upon (approved/rejected).
     *
     * @return array<self>
     */
    public static function getActionableStatuses(): array
    {
        return [self::PENDING, self::PARTIALLY_APPROVED];
    }

    /**
     * Check if this status is actionable.
     */
    public function isActionable(): bool
    {
        return in_array($this, self::getActionableStatuses(), true);
    }

    /**
     * Check if this status is finalized.
     */
    public function isFinalized(): bool
    {
        return in_array($this, self::getFinalizedStatuses(), true);
    }

    public function display(): string
    {
        return match ($this) {
            self::APPROVED => 'Approved',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
            self::FAILED => 'Failed',
            self::PARTIALLY_APPROVED => 'Partially Approved',
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::REJECTED => 'Rejected',
        };
    }
}
