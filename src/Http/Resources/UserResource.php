<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Resources;

use App\Models\User;
use Sourcetoad\EnhancedResources\Formatting\Attributes\Format;

/**
 * @property-read User $resource
 */
class UserResource extends Resource
{
    const string SIMPLE = 'simple';

    #[Format(self::SIMPLE)]
    public function simple(): array
    {
        return [
            'id' => $this->resource->getRouteKey(),
            'name' => [
                'first' => $this->resource->first_name,
                'full' => $this->resource->first_name . ' ' . $this->resource->last_name,
                'last' => $this->resource->last_name,
            ],
        ];
    }
}
