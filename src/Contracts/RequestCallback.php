<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * Contract for request lifecycle callbacks.
 *
 * Implement this interface to create callback classes that can be
 * registered in the config file:
 *
 * ```php
 * // config/maker-checker.php
 * 'callbacks' => [
 *     'after_approval' => [
 *         App\MakerChecker\Callbacks\SendApprovalNotification::class,
 *         App\MakerChecker\Callbacks\LogApproval::class,
 *     ],
 * ],
 * ```
 */
interface RequestCallback
{
    /**
     * Handle the callback.
     */
    public function handle(MakerCheckerRequest $request): void;
}
