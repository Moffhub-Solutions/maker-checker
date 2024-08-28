<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Models;

use App\Enums\Permission;
use App\Models\Traits\Sortable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Exception;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Searchable;
use Moffhub\MakerChecker\Contracts\MakerCheckerRequestInterface;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;

/**
 * @property int $id
 * @property string $code
 * @property string $description
 * @property array|null $payload
 * @property array|null $required_approvals
 * @property array|null $metadata
 * @property RequestStatus $status
 * @property RequestType $type
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string $maker_type
 * @property int $maker_id
 * @property string|null $checker_type
 * @property int|null $checker_id
 * @property string|Closure|null $executable
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $made_at
 * @property string $remarks
 * @property string|null $exception
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @property Model $subject
 * @property Model $maker
 * @property Model|null $checker
 *
 *
 *
 * @method static static create(array $attributes = [])
 * @method static static firstOrCreate(array $attributes, array $values = [])
 * @method static static firstOrNew(array $attributes, array $values = [])
 * @method static static firstWhere($column, $operator = null, $value = null, $boolean = 'and')
 * @method static static updateOrCreate(array $attributes, array $values = [])
 */
class MakerCheckerRequest extends Model implements MakerCheckerRequestInterface
{
    use Searchable {
        search as scoutSearch;
    }
    use Sortable;

    protected $guarded = ['id', 'code'];

    protected $casts = [
        'payload' => 'array',
        'metadata' => 'array',
        'status' => RequestStatus::class,
        'type' => RequestType::class,
        'made_at' => 'datetime',
        'checked_at' => 'datetime',
        'approvals' => 'array',
        'required_approvals' => 'array',
    ];

    public static function allowedFilters(): array
    {
        return [
            'type',
            'metadata',
            'payload',
            'status',
        ];
    }

    public static function allowedSorts(): array
    {
        return [
            'created_at',
            'type',
            'subject_type',
            'maker_type',
            'status',
        ];
    }

    public static function search(string $query = '', ?Closure $callback = null): ScoutBuilder
    {
        return self::scoutSearch($query,
            function (Builder $builder) use ($callback) {
                $builder->select('maker_checker_requests.*');

                if ($callback) {
                    $callback($builder);
                }
            });
    }

    /**
     * @throws Exception
     */
    public function addApproval(Model $approver, ?string $role = null): void
    {
        $approvals = $this->approvals ?? [];
        $role = $role ?: 'default';

        // Check if this approver has already approved
        foreach ($approvals as $approval) {
            if ($approval['checker_type'] === $approver->getMorphClass() && $approval['checker_id'] === $approver->getKey()) {
                throw new Exception('This approver has already approved the request.');
            }
        }

        // Add the new approver to the approvals array
        $approvals[] = [
            'checker_type' => $approver->getMorphClass(),
            'checker_id' => $approver->getKey(),
            'role' => $role,
            'approved_at' => now(),
        ];

        $this->update([
            'approvals' => $approvals,
        ]);
    }

    public function hasMetApprovalThreshold(): bool
    {
        $requiredApprovals = $this->required_approvals ?? [];
        /** @var array $actualApprovals */
        $actualApprovals = $this->approvals ?? [];

        if (empty($requiredApprovals)) {
            return count($actualApprovals) >= $this->defaultApprovalCount();
        }

        foreach ($requiredApprovals as $role => $count) {
            $actualCount = collect($actualApprovals)->where('role', $role)->count();
            if ($actualCount < $count) {
                return false;
            }
        }

        return true;
    }

    protected function defaultApprovalCount(): int
    {
        return 1; // Default to 1 approval needed if no specific role requirements are provided
    }

    public function getPendingRoles(): array
    {
        /** @var array $requiredApprovals */
        $requiredApprovals = $this->required_approvals ?? [];
        /** @var array $actualApprovals */
        $actualApprovals = $this->approvals ?? [];

        $pendingRoles = [];

        foreach ($requiredApprovals as $role => $requiredCount) {
            $actualCount = collect($actualApprovals)->where('role', $role)->count();
            if ($actualCount < $requiredCount) {
                $pendingRoles[$role] = $requiredCount - $actualCount;
            }
        }

        return $pendingRoles;
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => $this->getKey(),
            'code' => $this->code,
            'description' => $this->description,
            'status' => $this->status,
            'type' => $this->type,
        ];
    }

    public function scopeVisibleTo(Builder $builder, User $user): Builder
    {
        if ($user->hasPermission(Permission::MakerCheckerViewAny)) {
            return $builder;
        }

        return $builder->where('maker_id', '=', $user->getKey());
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withDefault();
    }

    public function maker(): MorphTo
    {
        return $this->morphTo();
    }

    public function checker(): MorphTo
    {
        return $this->morphTo()->withDefault();
    }

    public function isPending(): bool
    {
        return $this->isOfStatus(RequestStatus::PENDING);
    }

    public function isOfStatus(RequestStatus $status): bool
    {
        return $this->status === $status;
    }

    public function isProcessing(): bool
    {
        return $this->isOfStatus(RequestStatus::PROCESSING);
    }

    public function isApproved(): bool
    {
        return $this->isOfStatus(RequestStatus::APPROVED);
    }

    public function isRejected(): bool
    {
        return $this->isOfStatus(RequestStatus::REJECTED);
    }

    public function isExpired(): bool
    {
        return $this->isOfStatus(RequestStatus::EXPIRED);
    }

    public function isFailed(): bool
    {
        return $this->isOfStatus(RequestStatus::FAILED);
    }

    public function isOfType(RequestType $type): bool
    {
        return $this->type === $type;
    }

    public function scopeStatus(EloquentBuilder $query, RequestStatus $status): EloquentBuilder
    {
        return $query->where('status', $status);
    }
}
