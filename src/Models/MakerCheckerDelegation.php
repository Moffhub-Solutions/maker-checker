<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Models;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property string $delegator_type
 * @property int $delegator_id
 * @property string $delegate_type
 * @property int $delegate_id
 * @property string|null $scope
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property Model $delegator
 * @property Model|null $delegate
 */
class MakerCheckerDelegation extends Model
{
    protected $table = 'maker_checker_delegations';

    protected $guarded = ['id'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * @return MorphTo<Model, $this>
     */
    public function delegator(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function delegate(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope to get active (non-expired) delegations.
     *
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopeActive(EloquentBuilder $query): EloquentBuilder
    {
        return $query->where(function (EloquentBuilder $q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /**
     * Scope to get expired delegations.
     *
     * @param  EloquentBuilder<static>  $query
     * @return EloquentBuilder<static>
     */
    public function scopeExpired(EloquentBuilder $query): EloquentBuilder
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * Check if this delegation is currently active.
     */
    public function isActive(): bool
    {
        return $this->expires_at === null || $this->expires_at->isFuture();
    }

    /**
     * Check if this delegation has expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
