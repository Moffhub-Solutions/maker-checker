<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Repositories;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerConfig;

/**
 * Repository for managing maker-checker configurations in the database.
 *
 * This repository provides methods for CRUD operations on configs,
 * with built-in caching support for performance.
 */
class ConfigRepository
{
    protected string $cachePrefix = 'maker_checker_config:';

    protected int $cacheTtl = 3600; // 1 hour

    protected bool $cacheEnabled;

    public function __construct()
    {
        $this->cacheEnabled = config('maker-checker.cache_config', true);
        $this->cacheTtl = config('maker-checker.config_cache_ttl', 3600);
    }

    // =========================================================================
    // Query Methods
    // =========================================================================

    /**
     * Get config for a model and action.
     */
    public function getForModel(
        string $modelClass,
        RequestType $action,
        ?int $teamId = null
    ): ?MakerCheckerConfig {
        $cacheKey = $this->getCacheKey($modelClass, $action->value, $teamId);

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $config = MakerCheckerConfig::query()
            ->forModel($modelClass)
            ->forAction($action)
            ->forTeam($teamId)
            ->active()
            ->orderByRaw('action IS NULL') // Specific action first, then null (all actions)
            ->orderByRaw('team_id IS NULL') // Team-specific first, then global
            ->first();

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $config, $this->cacheTtl);
        }

        return $config;
    }

    /**
     * Get config for an executable.
     */
    public function getForExecutable(
        string $executableClass,
        ?int $teamId = null
    ): ?MakerCheckerConfig {
        $cacheKey = $this->getCacheKey($executableClass, 'execute', $teamId);

        if ($this->cacheEnabled && Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $config = MakerCheckerConfig::query()
            ->forExecutable($executableClass)
            ->forTeam($teamId)
            ->active()
            ->orderByRaw('team_id IS NULL')
            ->first();

        if ($this->cacheEnabled) {
            Cache::put($cacheKey, $config, $this->cacheTtl);
        }

        return $config;
    }

    /**
     * Get all configs for a model.
     *
     * @return Collection<MakerCheckerConfig>
     */
    public function getAllForModel(string $modelClass, ?int $teamId = null): Collection
    {
        return MakerCheckerConfig::query()
            ->forModel($modelClass)
            ->forTeam($teamId)
            ->active()
            ->orderBy('action')
            ->get();
    }

    /**
     * Get all active configs.
     *
     * @return Collection<MakerCheckerConfig>
     */
    public function getAll(?int $teamId = null): Collection
    {
        $query = MakerCheckerConfig::query()->active();

        if ($teamId !== null) {
            $query->forTeam($teamId);
        }

        return $query->orderBy('configurable_type')->orderBy('action')->get();
    }

    /**
     * Get all distinct configurable types (models/executables).
     *
     * @return Collection<string>
     */
    public function getConfigurableTypes(?int $teamId = null): Collection
    {
        $query = MakerCheckerConfig::query()->active();

        if ($teamId !== null) {
            $query->forTeam($teamId);
        }

        return $query->distinct()->pluck('configurable_type');
    }

    // =========================================================================
    // CRUD Methods
    // =========================================================================

    /**
     * Create a new config.
     */
    public function create(array $data): MakerCheckerConfig
    {
        $config = MakerCheckerConfig::create($data);
        $this->clearCacheForConfig($config);

        return $config;
    }

    /**
     * Update an existing config.
     */
    public function update(MakerCheckerConfig $config, array $data): MakerCheckerConfig
    {
        $this->clearCacheForConfig($config);
        $config->update($data);
        $this->clearCacheForConfig($config);

        return $config->fresh() ?? $config;
    }

    /**
     * Delete a config.
     */
    public function delete(MakerCheckerConfig $config): bool
    {
        $this->clearCacheForConfig($config);

        return (bool) $config->delete();
    }

    /**
     * Create or update a config for a model.
     */
    public function upsertForModel(
        string $modelClass,
        ?string $action = null,
        array $approvals = [],
        array $uniqueFields = [],
        ?int $teamId = null,
        ?string $description = null
    ): MakerCheckerConfig {
        $config = MakerCheckerConfig::updateOrCreate(
            [
                'configurable_type' => $modelClass,
                'action' => $action,
                'team_id' => $teamId,
            ],
            [
                'approvals' => $approvals,
                'unique_fields' => $uniqueFields,
                'description' => $description,
                'is_active' => true,
            ]
        );

        $this->clearCacheForConfig($config);

        return $config;
    }

    /**
     * Create or update a config for an executable.
     */
    public function upsertForExecutable(
        string $executableClass,
        array $approvals = [],
        array $uniqueFields = [],
        ?int $teamId = null,
        ?string $description = null
    ): MakerCheckerConfig {
        return $this->upsertForModel(
            $executableClass,
            RequestType::EXECUTE->value,
            $approvals,
            $uniqueFields,
            $teamId,
            $description
        );
    }

    /**
     * Enable a config.
     */
    public function enable(MakerCheckerConfig $config): MakerCheckerConfig
    {
        return $this->update($config, ['is_active' => true]);
    }

    /**
     * Disable a config.
     */
    public function disable(MakerCheckerConfig $config): MakerCheckerConfig
    {
        return $this->update($config, ['is_active' => false]);
    }

    /**
     * Set approval requirements for a config.
     *
     * @param  array<string, int>  $approvals
     */
    public function setApprovals(MakerCheckerConfig $config, array $approvals): MakerCheckerConfig
    {
        return $this->update($config, ['approvals' => $approvals]);
    }

    /**
     * Set unique fields for a config.
     *
     * @param  array<string>  $uniqueFields
     */
    public function setUniqueFields(MakerCheckerConfig $config, array $uniqueFields): MakerCheckerConfig
    {
        return $this->update($config, ['unique_fields' => $uniqueFields]);
    }

    // =========================================================================
    // Bulk Operations
    // =========================================================================

    /**
     * Import configs from an array.
     *
     * @param  array<array>  $configs
     * @return Collection<MakerCheckerConfig>
     */
    public function import(array $configs): Collection
    {
        $results = collect();

        foreach ($configs as $configData) {
            $config = $this->upsertForModel(
                $configData['configurable_type'],
                $configData['action'] ?? null,
                $configData['approvals'] ?? [],
                $configData['unique_fields'] ?? [],
                $configData['team_id'] ?? null,
                $configData['description'] ?? null
            );
            $results->push($config);
        }

        return $results;
    }

    /**
     * Export all configs to an array.
     */
    public function export(?int $teamId = null): array
    {
        return $this->getAll($teamId)
            ->map(fn(MakerCheckerConfig $config): array => [
                'configurable_type' => $config->configurable_type,
                'action' => $config->action,
                'approvals' => $config->approvals,
                'unique_fields' => $config->unique_fields,
                'team_id' => $config->team_id,
                'description' => $config->description,
                'is_active' => $config->is_active,
            ])
            ->toArray();
    }

    // =========================================================================
    // Cache Methods
    // =========================================================================

    /**
     * Clear all config cache.
     */
    public function clearCache(): void
    {
        // Note: In production, you might want to use tagged cache for better control
        // For now, we clear individual keys when configs are modified
        Cache::flush(); // Be careful with this in production!
    }

    /**
     * Clear cache for a specific config.
     */
    protected function clearCacheForConfig(MakerCheckerConfig $config): void
    {
        // Clear cache for all action types since null action applies to all
        foreach (RequestType::cases() as $action) {
            $cacheKey = $this->getCacheKey(
                $config->configurable_type,
                $action->value,
                $config->team_id
            );
            Cache::forget($cacheKey);

            // Also clear global (null team) cache
            if ($config->team_id !== null) {
                $globalCacheKey = $this->getCacheKey(
                    $config->configurable_type,
                    $action->value,
                    null
                );
                Cache::forget($globalCacheKey);
            }
        }
    }

    /**
     * Generate cache key.
     */
    protected function getCacheKey(string $configurableType, string $action, ?int $teamId): string
    {
        $teamPart = $teamId !== null ? "team:{$teamId}" : 'global';

        return "{$this->cachePrefix}{$configurableType}:{$action}:{$teamPart}";
    }
}
