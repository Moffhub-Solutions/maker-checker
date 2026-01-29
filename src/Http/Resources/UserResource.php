<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Contracts\MakerCheckerUserContract;
use Sourcetoad\EnhancedResources\Formatting\Attributes\Format;

/**
 * Resource for representing user/maker/checker in API responses.
 *
 * Works with any Eloquent model and has enhanced support for models
 * implementing MakerCheckerUserContract.
 *
 * @property-read Model $resource
 */
class UserResource extends Resource
{
    const string SIMPLE = 'simple';

    #[Format(self::SIMPLE)]
    public function simple(): array
    {
        $model = $this->resource;

        // Get ID using route key or primary key
        $id = $model->getRouteKey();

        // Build name data
        $name = $this->resolveName($model);

        return [
            'id' => $id,
            'name' => $name,
        ];
    }

    /**
     * Resolve the name data for the user model.
     */
    private function resolveName(Model $model): array
    {
        // Check for contract implementation
        if ($model instanceof MakerCheckerUserContract) {
            // Contract doesn't define name, so we still need to check attributes
        }

        // Try common name attribute patterns
        $firstName = $this->getAttribute($model, ['first_name', 'firstName', 'given_name']);
        $lastName = $this->getAttribute($model, ['last_name', 'lastName', 'family_name', 'surname']);
        $fullName = $this->getAttribute($model, ['name', 'full_name', 'fullName', 'display_name']);

        // Build the name array
        if ($firstName || $lastName) {
            return [
                'first' => $firstName,
                'full' => trim(($firstName ?? '').' '.($lastName ?? '')),
                'last' => $lastName,
            ];
        }

        if ($fullName) {
            // Try to split the full name
            $parts = explode(' ', $fullName, 2);

            return [
                'first' => $parts[0],
                'full' => $fullName,
                'last' => $parts[1] ?? null,
            ];
        }

        // Fallback to email or identifier
        $email = $this->getAttribute($model, ['email']);

        return [
            'first' => null,
            'full' => $email ?? (string) $model->getKey(),
            'last' => null,
        ];
    }

    /**
     * Get the first matching attribute from the model.
     *
     * @param  array<string>  $attributes
     */
    private function getAttribute(Model $model, array $attributes): ?string
    {
        foreach ($attributes as $attr) {
            $value = $model->getAttribute($attr);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
