<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Moffhub\MakerChecker\Relations\Concerns\InterceptsPivotCalls;
use Moffhub\MakerChecker\Relations\Concerns\ResolvesRelationInterception;

/**
 * MorphToMany whose pivot mutations are routed through maker-checker for
 * opted-in relations. Returned by InterceptsRelationships::newMorphToMany().
 *
 * @template TRelatedModel of \Illuminate\Database\Eloquent\Model
 * @template TDeclaringModel of \Illuminate\Database\Eloquent\Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
class ApprovableMorphToMany extends MorphToMany
{
    use InterceptsPivotCalls;
    use ResolvesRelationInterception;
}
