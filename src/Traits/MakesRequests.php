<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Traits;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\RequestBuilder;

trait MakesRequests
{
    public function requestToCreate(string $modelToCreate, array $payload, $madeByInstance): RequestBuilder
    {
        return MakerChecker::request()->toCreate($modelToCreate, $payload)->madeBy($madeByInstance);
    }

    public function requestToUpdate(Model $modelToUpdate, array $payload, $madeByInstance): RequestBuilder
    {
        return MakerChecker::request()->toUpdate($modelToUpdate, $payload)->madeBy($madeByInstance);
    }

    public function requestToDelete(Model $modelToDelete, $madeByInstance): RequestBuilder
    {
        return MakerChecker::request()->toDelete($modelToDelete)->madeBy($madeByInstance);
    }

    /**
     * @throws Exception
     */
    public function requestToExecute(string $executable, array $payload, $madeByInstance): RequestBuilder
    {
        return MakerChecker::request()->toExecute($executable, $payload)->madeBy($madeByInstance);
    }
}
