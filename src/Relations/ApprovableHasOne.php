<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Moffhub\MakerChecker\Relations\Concerns\InterceptsChildWrites;
use Moffhub\MakerChecker\Relations\Concerns\ResolvesRelationInterception;

/**
 * HasOne whose save/saveMany/create/createMany are routed through
 * maker-checker for opted-in relations. Returned by
 * InterceptsRelationships::newHasOne().
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends HasOne<TRelatedModel, TDeclaringModel>
 */
class ApprovableHasOne extends HasOne
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
