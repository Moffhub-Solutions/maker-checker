<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

interface MakerCheckerRequestInterface
{
    /**
     * @return MorphTo<Model, MakerCheckerRequest>
     */
    public function subject(): MorphTo;

    /**
     * @return MorphTo<Model, MakerCheckerRequest>
     */
    public function maker(): MorphTo;

    /**
     * @return MorphTo<Model, MakerCheckerRequest>
     */
    public function checker(): MorphTo;

    public function isOfStatus(RequestStatus $status): bool;

    public function isOfType(RequestType $type): bool;

    /**
     * @param  Builder<Model>  $query
     *
     * @return Builder<Model>
     */
    public function scopeStatus(Builder $query, RequestStatus $status): Builder;
}
