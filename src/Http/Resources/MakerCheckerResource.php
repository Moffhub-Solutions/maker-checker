<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Resources;

use Moffhub\MakerChecker\Models\MakerCheckerRequest;
use Sourcetoad\EnhancedResources\Formatting\Attributes\Format;

/**
 * @property-read MakerCheckerRequest $resource
 */
class MakerCheckerResource extends Resource
{
    const string BASE = 'base';
    const string SIMPLE = 'simple';

    #[Format(self::BASE)]
    public function base(): array
    {
        return [
            ...$this->simple(),
            'created_at' => $this->resource->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->resource->updated_at?->toIso8601ZuluString(),
            'code' => $this->resource->code,
            'metadata' => $this->resource->metadata,
            'executable' => $this->resource->executable,
            'payload' => $this->resource->payload,
            'remarks' => $this->resource->remarks,
            'subject_type' => $this->resource->subject_type,
            'subject_id' => $this->resource->subject_id,
            'maker_type' => $this->resource->maker_type,
            'maker_id' => $this->resource->maker_id,
            'made_at' => $this->resource->made_at?->toIso8601ZuluString(),
            'checker_type' => $this->resource->checker_type,
            'checker_id' => $this->resource->checker_id,
            'checked_at' => $this->resource->checked_at?->toIso8601ZuluString(),
            'maker' => UserResource::make($this->resource->maker)->format(UserResource::SIMPLE),
        ];
    }

    #[Format(self::SIMPLE)]
    public function simple(): array
    {
        return [
            'id' => (string) $this->resource->getKey(),
            'description' => $this->resource->description,
            'status' => $this->resource->status->display(),
            'type' => $this->resource->type->display(),
        ];
    }
}
