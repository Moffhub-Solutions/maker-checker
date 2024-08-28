<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Facades;

use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Facade;
use Moffhub\MakerChecker\MakerCheckerRequestManager;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Moffhub\MakerChecker\RequestBuilder;

/**
 * @method static RequestBuilder request()
 * @method static void afterInitiating(Closure $callback)
 * @method static void afterApproving(Closure $callback)
 * @method static void afterRejecting(Closure $callback)
 * @method static void onFailure(Closure $callback)
 * @method static MakerCheckerRequest approve(MakerCheckerRequest $request, User $model, string|null $remarks)
 * @method static MakerCheckerRequest reject(MakerCheckerRequest $request, User $model, string|null $remarks)
 * @see MakerCheckerRequestManager
 */
class MakerChecker extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MakerCheckerRequestManager::class;
    }
}
