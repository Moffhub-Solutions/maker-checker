<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Tests\Fixtures;

use Moffhub\MakerChecker\Contracts\ExecutableRequest;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

class TestExecutable extends ExecutableRequest
{
    public static bool $executed = false;

    public static ?MakerCheckerRequest $lastRequest = null;

    public function execute(MakerCheckerRequest $request): void
    {
        self::$executed = true;
        self::$lastRequest = $request;
    }

    public function uniqueBy(): array
    {
        return ['action_id'];
    }

    public function beforeApproval(MakerCheckerRequest $request): void
    {
        // Pre-approval hook
    }

    public function afterApproval(MakerCheckerRequest $request): void
    {
        // Post-approval hook
    }

    public function beforeRejection(MakerCheckerRequest $request): void
    {
        // Pre-rejection hook
    }

    public function afterRejection(MakerCheckerRequest $request): void
    {
        // Post-rejection hook
    }

    public function onFailure(MakerCheckerRequest $request): void
    {
        // Failure hook
    }

    public static function reset(): void
    {
        self::$executed = false;
        self::$lastRequest = null;
    }
}
