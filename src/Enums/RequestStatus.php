<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Enums;

enum RequestStatus: string
{
    case PENDING = 'pending';

    case PROCESSING = 'processing';

    case APPROVED = 'approved';

    case REJECTED = 'rejected';

    case EXPIRED = 'expired';

    case FAILED = 'failed';

    public static function getFinalizedStatuses(): array
    {
        return [self::APPROVED, self::REJECTED, self::EXPIRED, self::FAILED];
    }

    public function display(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PROCESSING => 'Processing',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::EXPIRED => 'Expired',
            self::FAILED => 'Failed',
        };
    }
}
