<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Enums;

enum RequestStatus: string
{
    case APPROVED = 'approved';
    case EXPIRED = 'expired';
    case FAILED = 'failed';
    case PARTIALLY_APPROVED = 'partially_approved';
    case PENDING = 'pending';
    case PROCESSING = 'processing';
    case REJECTED = 'rejected';

    public static function getFinalizedStatuses(): array
    {
        return [self::APPROVED, self::REJECTED, self::EXPIRED, self::FAILED];
    }

    public function display(): string
    {
        return match ($this) {
            self::APPROVED => 'Approved',
            self::EXPIRED => 'Expired',
            self::FAILED => 'Failed',
            self::PARTIALLY_APPROVED => 'Partially Approved',
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::REJECTED => 'Rejected',
        };
    }
}
