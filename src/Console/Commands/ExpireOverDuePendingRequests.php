<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Events\RequestExpired;
use Moffhub\MakerChecker\MakerCheckerServiceProvider;

class ExpireOverDuePendingRequests extends Command
{
    protected $signature = 'maker-checker:expire-overdue
                            {--dry-run : Show what would be expired without actually expiring}';

    protected $description = 'Identify and expire all overdue pending and partially approved requests.';

    public function handle(): int
    {
        $expirationInMinutes = config('maker-checker.request_expiration_in_minutes');

        if (!$expirationInMinutes) {
            $this->error('A value needs to be set for the `request_expiration_in_minutes` configuration for this command to be effective.');

            return self::FAILURE;
        }

        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();
        $cutoffTime = Carbon::now()->subMinutes((int) $expirationInMinutes);

        $query = $requestModel::query()
            ->whereIn('status', [RequestStatus::PENDING, RequestStatus::PARTIALLY_APPROVED])
            ->where('created_at', '<=', $cutoffTime);

        $count = $query->count();

        if ($count === 0) {
            $this->info('No overdue requests found.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Would expire {$count} overdue request(s).");
            $this->table(
                ['ID', 'Code', 'Status', 'Created At'],
                $query->get(['id', 'code', 'status', 'created_at'])->map(fn($r): array => [
                    $r->id,
                    $r->code,
                    $r->status->display(),
                    $r->created_at->toDateTimeString(),
                ])->toArray()
            );

            return self::SUCCESS;
        }

        // Fetch requests before updating so we can dispatch events with full data
        $requests = $query->with('maker')->get();

        $requestModel::query()
            ->whereIn('id', $requests->pluck('id'))
            ->update(['status' => RequestStatus::EXPIRED]);

        foreach ($requests as $request) {
            $request->status = RequestStatus::EXPIRED;
            event(RequestExpired::fromRequest($request));
        }

        $this->info("{$count} pending/partially approved request(s) marked as expired successfully.");

        return self::SUCCESS;
    }
}
