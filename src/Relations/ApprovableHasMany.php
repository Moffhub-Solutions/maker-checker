<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Moffhub\MakerChecker\Relations\Concerns\InterceptsChildWrites;
use Moffhub\MakerChecker\Relations\Concerns\ResolvesRelationInterception;

/**
 * HasMany whose save/saveMany/create/createMany are routed through
 * maker-checker for opted-in relations. Returned by
 * InterceptsRelationships::newHasMany().
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasMany<TRelatedModel, TDeclaringModel>
 */
class ApprovableHasMany extends HasMany
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
