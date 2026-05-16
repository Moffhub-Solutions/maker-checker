<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Moffhub\MakerChecker\Relations\Concerns\InterceptsPivotCalls;
use Moffhub\MakerChecker\Relations\Concerns\ResolvesRelationInterception;

/**
 * BelongsToMany whose pivot mutations are routed through maker-checker for
 * opted-in relations. Returned by InterceptsRelationships::newBelongsToMany().
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel>
 */
class ApprovableBelongsToMany extends BelongsToMany
{
    use InterceptsPivotCalls;
    use ResolvesRelationInterception;
}
