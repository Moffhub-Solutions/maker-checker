<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Moffhub\MakerChecker\Relations\Concerns\InterceptsChildWrites;
use Moffhub\MakerChecker\Relations\Concerns\ResolvesRelationInterception;

/**
 * MorphOne whose save/saveMany/create/createMany are routed through
 * maker-checker for opted-in relations. Returned by
 * InterceptsRelationships::newMorphOne().
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphOne<TRelatedModel, TDeclaringModel>
 */
class ApprovableMorphOne extends MorphOne
{
    use InterceptsChildWrites;
    use ResolvesRelationInterception;

    private ?string $approvalRelationName = null;

    public function setRelationName(?string $name): static
    {
        $this->approvalRelationName = $name;

        return $this;
    }

    public function getRelationName(): ?string
    {
        return $this->approvalRelationName;
    }
}
