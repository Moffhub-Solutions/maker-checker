<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Moffhub\MakerChecker\Relations\Concerns\ResolvesRelationInterception;

/**
 * BelongsTo whose associate()/dissociate() are routed through maker-checker
 * for opted-in relations. Returned by InterceptsRelationships::newBelongsTo().
 *
 * Note: associate()/dissociate() only set the foreign key in memory; the
 * change is normally persisted by a subsequent save(). When intercepted the
 * foreign key is left untouched and a pending request is created instead.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsTo<TRelatedModel, TDeclaringModel>
 */
class ApprovableBelongsTo extends BelongsTo
{
    use ResolvesRelationInterception;

    public function associate($model)
    {
        return $this->interceptRelation(
            'associate',
            fn() => parent::associate($model),
            $model,
        );
    }

    public function dissociate()
    {
        return $this->interceptRelation(
            'dissociate',
            fn() => parent::dissociate(),
        );
    }

    protected function interceptionParent(): Model
    {
        return $this->getChild();
    }
}
