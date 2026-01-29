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

/**
 * @method static RequestBuilder request()
 * @method static ConfigResolver config()
 * @method static void afterInitiating(Closure $callback)
 * @method static void afterApproving(Closure $callback)
 * @method static void afterRejecting(Closure $callback)
 * @method static void afterCancelling(Closure $callback)
 * @method static void onFailure(Closure $callback)
 * @method static MakerCheckerRequest approve(MakerCheckerRequest $request, Model $model, string|null $role = null, string|null $remarks = null)
 * @method static MakerCheckerRequest reject(MakerCheckerRequest $request, Model $model, string|null $remarks = null)
 * @method static MakerCheckerRequest cancel(MakerCheckerRequest $request, Model $model, string|null $remarks = null)
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
