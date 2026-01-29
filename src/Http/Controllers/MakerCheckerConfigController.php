<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Models\MakerCheckerConfig;
use Moffhub\MakerChecker\Repositories\ConfigRepository;

/**
 * API Controller for managing maker-checker configurations.
 *
 * Provides CRUD endpoints for dynamically configuring approval requirements
 * per model/action when using the 'database' config driver.
 *
 * Routes (suggested):
 * - GET    /api/maker-checker/configs          - List all configs
 * - POST   /api/maker-checker/configs          - Create a new config
 * - GET    /api/maker-checker/configs/{id}     - Get a specific config
 * - PUT    /api/maker-checker/configs/{id}     - Update a config
 * - DELETE /api/maker-checker/configs/{id}     - Delete a config
 * - POST   /api/maker-checker/configs/import   - Bulk import configs
 * - GET    /api/maker-checker/configs/export   - Export all configs
 */
class MakerCheckerConfigController extends Controller
{
    public function __construct(
        protected ConfigRepository $repository
    ) {}

    /**
     * List all configurations.
     *
     * @queryParam team_id integer Filter by team ID
     * @queryParam configurable_type string Filter by model/executable class
     * @queryParam action string Filter by action (create, update, delete, execute)
     */
    public function index(Request $request): JsonResponse
    {
        $teamId = $request->integer('team_id') ?: null;

        $configs = $this->repository->getAll($teamId);

        // Apply filters
        if ($request->filled('configurable_type')) {
            $configs = $configs->where('configurable_type', $request->string('configurable_type'));
        }

        if ($request->filled('action')) {
            $configs = $configs->where('action', $request->string('action'));
        }

        return response()->json([
            'data' => $configs->values()->map(fn(MakerCheckerConfig $config) => $this->formatConfig($config)),
        ]);
    }

    /**
     * Create a new configuration.
     *
     * @bodyParam configurable_type string required The model or executable class name
     * @bodyParam action string The action type (create, update, delete, execute) or null for all
     * @bodyParam approvals object Role-based approval requirements ['role' => count] (legacy format)
     * @bodyParam approvals.roles object Role-based approval requirements ['role' => count] (new format)
     * @bodyParam approvals.users array User emails or IDs required to approve
     * @bodyParam unique_fields array Fields to check for uniqueness
     * @bodyParam team_id integer Optional team ID for multi-tenant configs
     * @bodyParam description string Optional human-readable description
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'configurable_type' => 'required|string',
            'action' => 'nullable|string|in:create,update,delete,execute',
            'approvals' => 'nullable|array',
            'unique_fields' => 'nullable|array',
            'unique_fields.*' => 'string',
            'team_id' => 'nullable|integer',
            'description' => 'nullable|string|max:500',
        ]);

        $approvals = $this->normalizeApprovals($request->input('approvals', []));

        $config = $this->repository->upsertForModel(
            $request->input('configurable_type'),
            $request->input('action'),
            $approvals,
            $request->input('unique_fields', []),
            $request->input('team_id'),
            $request->input('description')
        );

        return response()->json([
            'message' => 'Configuration created successfully',
            'data' => $this->formatConfig($config),
        ], 201);
    }

    /**
     * Get a specific configuration.
     */
    public function show(MakerCheckerConfig $config): JsonResponse
    {
        return response()->json([
            'data' => $this->formatConfig($config),
        ]);
    }

    /**
     * Update a configuration.
     *
     * @bodyParam approvals object Role-based approval requirements ['role' => count] (legacy format)
     * @bodyParam approvals.roles object Role-based approval requirements ['role' => count] (new format)
     * @bodyParam approvals.users array User emails or IDs required to approve
     * @bodyParam unique_fields array Fields to check for uniqueness
     * @bodyParam description string Optional human-readable description
     * @bodyParam is_active boolean Whether the config is active
     */
    public function update(Request $request, MakerCheckerConfig $config): JsonResponse
    {
        $request->validate([
            'approvals' => 'nullable|array',
            'unique_fields' => 'nullable|array',
            'unique_fields.*' => 'string',
            'description' => 'nullable|string|max:500',
            'is_active' => 'nullable|boolean',
        ]);

        $updateData = [];

        if ($request->has('approvals')) {
            $updateData['approvals'] = $this->normalizeApprovals($request->input('approvals', []));
        }

        if ($request->has('unique_fields')) {
            $updateData['unique_fields'] = $request->input('unique_fields');
        }

        if ($request->has('description')) {
            $updateData['description'] = $request->input('description');
        }

        if ($request->has('is_active')) {
            $updateData['is_active'] = $request->boolean('is_active');
        }

        $config = $this->repository->update($config, $updateData);

        return response()->json([
            'message' => 'Configuration updated successfully',
            'data' => $this->formatConfig($config),
        ]);
    }

    /**
     * Delete a configuration.
     */
    public function destroy(MakerCheckerConfig $config): JsonResponse
    {
        $this->repository->delete($config);

        return response()->json([
            'message' => 'Configuration deleted successfully',
        ]);
    }

    /**
     * Enable a configuration.
     */
    public function enable(MakerCheckerConfig $config): JsonResponse
    {
        $config = $this->repository->enable($config);

        return response()->json([
            'message' => 'Configuration enabled successfully',
            'data' => $this->formatConfig($config),
        ]);
    }

    /**
     * Disable a configuration.
     */
    public function disable(MakerCheckerConfig $config): JsonResponse
    {
        $config = $this->repository->disable($config);

        return response()->json([
            'message' => 'Configuration disabled successfully',
            'data' => $this->formatConfig($config),
        ]);
    }

    /**
     * Bulk import configurations.
     *
     * @bodyParam configs array required Array of configuration objects
     */
    public function import(Request $request): JsonResponse
    {
        $request->validate([
            'configs' => 'required|array',
            'configs.*.configurable_type' => 'required|string',
            'configs.*.action' => 'nullable|string|in:create,update,delete,execute',
            'configs.*.approvals' => 'nullable|array',
            'configs.*.unique_fields' => 'nullable|array',
            'configs.*.team_id' => 'nullable|integer',
            'configs.*.description' => 'nullable|string|max:500',
        ]);

        // Normalize approvals in each config
        $configs = array_map(function ($config) {
            if (isset($config['approvals'])) {
                $config['approvals'] = $this->normalizeApprovals($config['approvals']);
            }

            return $config;
        }, $request->input('configs', []));

        $configs = $this->repository->import($configs);

        return response()->json([
            'message' => sprintf('%d configuration(s) imported successfully', $configs->count()),
            'data' => $configs->map(fn(MakerCheckerConfig $config) => $this->formatConfig($config)),
        ]);
    }

    /**
     * Export all configurations.
     *
     * @queryParam team_id integer Filter by team ID
     */
    public function export(Request $request): JsonResponse
    {
        $teamId = $request->integer('team_id') ?: null;

        return response()->json([
            'data' => $this->repository->export($teamId),
        ]);
    }

    /**
     * Get available action types.
     */
    public function actions(): JsonResponse
    {
        return response()->json([
            'data' => collect(RequestType::cases())->map(fn(RequestType $type) => [
                'value' => $type->value,
                'label' => $type->display(),
            ]),
        ]);
    }

    /**
     * Get all distinct configurable types.
     *
     * @queryParam team_id integer Filter by team ID
     */
    public function types(Request $request): JsonResponse
    {
        $teamId = $request->integer('team_id') ?: null;

        return response()->json([
            'data' => $this->repository->getConfigurableTypes($teamId),
        ]);
    }

    /**
     * Format a config for API response.
     *
     * @return array<string, mixed>
     */
    protected function formatConfig(MakerCheckerConfig $config): array
    {
        return [
            'id' => $config->id,
            'configurable_type' => $config->configurable_type,
            'configurable_name' => class_basename($config->configurable_type),
            'action' => $config->action,
            'action_label' => $config->getActionType()?->display() ?? 'All Actions',
            'approvals' => $config->getApprovals(),
            'role_approvals' => $config->getRoleApprovals(),
            'user_approvals' => $config->getUserApprovals(),
            'requires_user_approvals' => $config->requiresUserApprovals(),
            'unique_fields' => $config->getUniqueFields(),
            'is_active' => $config->is_active,
            'team_id' => $config->team_id,
            'description' => $config->description,
            'created_at' => $config->created_at->toIso8601String(),
            'updated_at' => $config->updated_at->toIso8601String(),
        ];
    }

    /**
     * Normalize approvals structure.
     *
     * Expected format:
     * ['roles' => ['admin' => 1], 'users' => ['user@example.com']]
     *
     * @return array{roles?: array<string, int>, users?: array<string>}
     */
    protected function normalizeApprovals(array $approvals): array
    {
        return array_filter([
            'roles' => $approvals['roles'] ?? [],
            'users' => $approvals['users'] ?? [],
        ], fn($v) => !empty($v));
    }
}
