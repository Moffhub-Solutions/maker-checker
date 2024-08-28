<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Contracts;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;

abstract class ExecutableRequest
{
    abstract public function execute(MakerCheckerRequest $request): void;

    /**
     * Set the fields in the payload that mark the request as unique.
     */
    public function uniqueBy(): array
    {
        return [];
    }

    /**
     * Define an action to be performed before a request is approved.
     */
    public function beforeApproval(MakerCheckerRequest $request): void
    {
        //
    }

    /**
     * Define an action to be performed after a request is approved.
     */
    public function afterApproval(MakerCheckerRequest $request): void
    {
        //
    }

    /**
     * Define an action to be performed before a request is rejected.
     */
    public function beforeRejection(MakerCheckerRequest $request): void
    {
        //
    }

    /**
     * Define an action to be performed after a request is rejected.
     */
    public function afterRejection(MakerCheckerRequest $request): void
    {
        //
    }

    /**
     * Define an action to be performed when a request fails to be processed.
     */
    public function onFailure(MakerCheckerRequest $request): void
    {
        //
    }
}
