<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;

interface MakerCheckerRequestInterface
{
    public function subject(): MorphTo;

    public function maker(): MorphTo;

    public function checker(): MorphTo;

    public function isOfStatus(RequestStatus $status): bool;

    public function isOfType(RequestType $type): bool;

    public function scopeStatus(Builder $query, RequestStatus $status): Builder;
}
