<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Resources;

use Closure;
use Illuminate\Testing\Fluent\AssertableJson;
use InvalidArgumentException;
use Sourcetoad\EnhancedResources\Resource as EnhancedResource;

abstract class Resource extends EnhancedResource
{
    public function toJsonAssertion(bool $wrapped = true): Closure
    {
        return function (AssertableJson $json) use ($wrapped) {
            $content = $wrapped ? (string) $this->response()->getContent() : $this->toJson();
            $expectedData = json_decode($content, true);

            if (!is_array($expectedData)) {
                // This is impossible to trigger without writing a broken resource
                // @codeCoverageIgnoreStart
                throw new InvalidArgumentException('Invalid data returned from resource');
                // @codeCoverageIgnoreEnd
            }

            $json->whereAll($expectedData);
        };
    }
}
