<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Resources;

use Illuminate\Database\Eloquent\Model;
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
            'maker' => $this->formatUser($this->resource->maker),
            'checker' => $this->formatUser($this->resource->checker),
            'required_approvals' => $this->resource->required_approvals ?? [],
            'current_approvals' => $this->resource->approvals ?? [],
            'pending_roles' => $this->resource->getPendingRoles(),
            'pending_users' => $this->resource->getPendingUsers(),
            'requires_user_approvals' => $this->resource->requiresUserApprovals(),
            'is_fully_approved' => $this->resource->hasMetApprovalThreshold(),
            'notes' => $this->resource->notes->map(fn($note) => [
                'id' => $note->id,
                'user_type' => $note->user_type,
                'user_id' => $note->user_id,
                'action' => $note->action,
                'note' => $note->note,
                'created_at' => $note->created_at?->toIso8601ZuluString(),
            ])->toArray(),
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

    /**
     * Format a user model using the configured resource class.
     */
    protected function formatUser(?Model $user): mixed
    {
        if (!$user instanceof Model) {
            return null;
        }

        $resourceClass = config('maker-checker.user_resource');

        if ($resourceClass && class_exists($resourceClass)) {
            $resource = $resourceClass::make($user);

            // Check if the resource has a SIMPLE format method
            if (defined("$resourceClass::SIMPLE")) {
                return $resource->format($resourceClass::SIMPLE);
            }

            return $resource;
        }

        // Default to built-in UserResource
        return UserResource::make($user)->format(UserResource::SIMPLE);
    }
}
