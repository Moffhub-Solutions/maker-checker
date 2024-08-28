<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class ExpireOverDuePendingRequests extends Command
{
    protected $signature = 'expire-overdue-requests';

    protected $description = 'identify and expire all overdue pending requests.';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $expirationInMinutes = config('maker-checker.request_expiration_in_minutes');

        if (!$expirationInMinutes) {
            $this->error('A value needs to be set for the `request_expiration_in_minutes` configuration for this command to be effected');

            return 0;
        }

        MakerCheckerRequest::query()->where('status', RequestStatus::PENDING)
            ->where('created_at', '<=', Carbon::now()->subMinutes($expirationInMinutes))
            ->update(['status' => RequestStatus::EXPIRED]);

        $this->info('Pending requests marked as expired successfully.');

        return 0;
    }

}
