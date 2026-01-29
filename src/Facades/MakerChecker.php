<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Facades;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Moffhub\MakerChecker\ConfigResolver;
use Moffhub\MakerChecker\MakerCheckerRequestManager;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\RequestBuilder;
use Moffhub\MakerChecker\Services\CallbackService;
use Moffhub\MakerChecker\Services\NotificationService;

/**
 * Convenience methods for creating requests (auto-inject authenticated user as maker):
 * @method static MakerCheckerRequest create(string $modelClass, array $attributes, string|null $description = null)
 * @method static MakerCheckerRequest update(Model $model, array $attributes, string|null $description = null)
 * @method static MakerCheckerRequest delete(Model $model, string|null $description = null)
 * @method static MakerCheckerRequest execute(string $executable, array $payload = [], string|null $description = null)
 *
 * Request builder for advanced usage:
 * @method static RequestBuilder request()
 *
 * Configuration:
 * @method static ConfigResolver config()
 *
 * Actions (approver/rejector/canceller is optional, defaults to authenticated user):
 * @method static MakerCheckerRequest approve(MakerCheckerRequest $request, Model|null $approver = null, string|null $role = null, string|null $remarks = null)
 * @method static MakerCheckerRequest reject(MakerCheckerRequest $request, Model|null $rejector = null, string|null $remarks = null)
 * @method static MakerCheckerRequest cancel(MakerCheckerRequest $request, Model|null $canceller = null, string|null $remarks = null)
 *
 * Event listeners:
 * @method static void afterInitiating(Closure $callback)
 * @method static void afterApproving(Closure $callback)
 * @method static void afterRejecting(Closure $callback)
 * @method static void afterCancelling(Closure $callback)
 * @method static void onFailure(Closure $callback)
 *
 * Notifications:
 * @method static NotificationService notifications()
 * @method static void notifyApprovers(MakerCheckerRequest $request, bool $sequential = false)
 * @method static void notifyNextApprovers(MakerCheckerRequest $request)
 *
 * Callbacks:
 * @method static CallbackService callbacks()
 *
 * @see MakerCheckerRequestManager
 */
class MakerChecker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MakerCheckerRequestManager::class;
    }
}
