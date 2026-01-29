<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Moffhub\MakerChecker\Enums\RequestType;

/**
 * Database-driven configuration for maker-checker approval requirements.
 *
 * This model allows storing and managing approval configurations in the database,
 * enabling dynamic configuration via API without code changes.
 *
 * Approvals format:
 * ['roles' => ['admin' => 1], 'users' => ['user@example.com']]
 *
 * @property int $id
 * @property string $configurable_type Model class name or executable class name
 * @property string|null $action RequestType value (create, update, delete, execute) or null for all actions
 * @property array{roles?: array<string, int>, users?: array<string>} $approvals Approval requirements
 * @property array<string> $unique_fields Fields to check for uniqueness
 * @property bool $is_active Whether this config is active
 * @property int|null $team_id Optional team ID for multi-tenant configs
 * @property array<string, mixed>|null $metadata Additional configuration data
 * @property string|null $description Human-readable description
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @method static Builder<static> forModel(string $modelClass)
 * @method static Builder<static> forExecutable(string $executableClass)
 * @method static Builder<static> forAction(RequestType|string|null $action)
 * @method static Builder<static> active()
 * @method static Builder<static> forTeam(?int $teamId)
 * @method static static create(array<string, mixed> $attributes = [])
 * @method static static firstOrCreate(array<string, mixed> $attributes = [], array<string, mixed> $values = [])
 * @method static static updateOrCreate(array<string, mixed> $attributes, array<string, mixed> $values = [])
 *
 * @mixin Builder<static>
 */
class MakerCheckerConfig extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'approvals' => 'array',
        'unique_fields' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'team_id' => 'integer',
    ];

    protected $attributes = [
        'approvals' => '[]',
        'unique_fields' => '[]',
        'is_active' => true,
    ];

    #[\Override]
    public function getTable(): string
    {
        return config('maker-checker.config_table_name', 'maker_checker_configs');
    }

    // =========================================================================
    // Scopes
    // =========================================================================

    /**
     * Scope to filter by model class.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForModel(Builder $query, string $modelClass): Builder
    {
        return $query->where('configurable_type', $modelClass);
    }

    /**
     * Scope to filter by executable class.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForExecutable(Builder $query, string $executableClass): Builder
    {
        return $query->where('configurable_type', $executableClass);
    }

    /**
     * Scope to filter by action type.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAction(Builder $query, RequestType|string|null $action): Builder
    {
        if ($action instanceof RequestType) {
            $action = $action->value;
        }

        return $query->where(function ($q) use ($action): void {
            $q->where('action', $action)
                ->orWhereNull('action'); // null means applies to all actions
        });
    }

    /**
     * Scope to filter active configs only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter by team (multi-tenant support).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTeam(Builder $query, ?int $teamId): Builder
    {
        return $query->where(function ($q) use ($teamId): void {
            $q->where('team_id', $teamId)
                ->orWhereNull('team_id'); // null means global config
        });
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Get the approval requirements as an array.
     *
     * @return array{roles?: array<string, int>, users?: array<string>}
     */
    public function getApprovals(): array
    {
        return $this->approvals ?? [];
    }

    /**
     * Get role-based approval requirements.
     *
     * @return array<string, int>
     */
    public function getRoleApprovals(): array
    {
        return $this->approvals['roles'] ?? [];
    }

    /**
     * Get user-specific approval requirements.
     *
     * @return array<string>
     */
    public function getUserApprovals(): array
    {
        return $this->approvals['users'] ?? [];
    }

    /**
     * Check if this config requires user-specific approvals.
     */
    public function requiresUserApprovals(): bool
    {
        return !empty($this->approvals['users']);
    }

    /**
     * Get the unique fields as an array.
     *
     * @return array<string>
     */
    public function getUniqueFields(): array
    {
        return $this->unique_fields ?? [];
    }

    /**
     * Check if this config applies to a specific action.
     */
    public function appliesTo(RequestType|string $action): bool
    {
        if ($this->action === null) {
            return true; // applies to all actions
        }

        $actionValue = $action instanceof RequestType ? $action->value : $action;

        return $this->action === $actionValue;
    }

    /**
     * Get the RequestType enum for this config's action.
     */
    public function getActionType(): ?RequestType
    {
        if ($this->action === null) {
            return null;
        }

        return RequestType::tryFrom($this->action);
    }

    // =========================================================================
    // Static Factory Methods
    // =========================================================================

    /**
     * Create a new config for a model.
     */
    public static function createForModel(
        string $modelClass,
        ?string $action = null,
        array $approvals = [],
        array $uniqueFields = [],
        ?int $teamId = null
    ): static {
        return static::create([
            'configurable_type' => $modelClass,
            'action' => $action,
            'approvals' => $approvals,
            'unique_fields' => $uniqueFields,
            'team_id' => $teamId,
            'is_active' => true,
        ]);
    }

    /**
     * Create a new config for an executable.
     */
    public static function createForExecutable(
        string $executableClass,
        array $approvals = [],
        array $uniqueFields = [],
        ?int $teamId = null
    ): static {
        return static::create([
            'configurable_type' => $executableClass,
            'action' => RequestType::EXECUTE->value,
            'approvals' => $approvals,
            'unique_fields' => $uniqueFields,
            'team_id' => $teamId,
            'is_active' => true,
        ]);
    }

    /**
     * Find or create a config for a model and action.
     */
    public static function findOrCreateForModel(
        string $modelClass,
        ?string $action = null,
        ?int $teamId = null
    ): static {
        return static::firstOrCreate([
            'configurable_type' => $modelClass,
            'action' => $action,
            'team_id' => $teamId,
        ], [
            'approvals' => [],
            'unique_fields' => [],
            'is_active' => true,
        ]);
    }
}
