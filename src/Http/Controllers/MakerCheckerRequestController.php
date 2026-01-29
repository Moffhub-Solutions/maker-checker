<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Moffhub\MakerChecker\Contracts\MakerCheckerUserContract;
use Moffhub\MakerChecker\Enums\RequestStatus;
use Moffhub\MakerChecker\Enums\RequestType;
use Moffhub\MakerChecker\Facades\MakerChecker;
use Moffhub\MakerChecker\Http\Resources\MakerCheckerResource;
use Moffhub\MakerChecker\MakerCheckerServiceProvider;
use Moffhub\MakerChecker\Models\MakerCheckerRequest;

/**
 * API Controller for viewing and managing maker-checker requests.
 *
 * Provides endpoints for listing requests, viewing approvals, and performing
 * approve/reject/cancel actions.
 *
 * Routes (suggested):
 * - GET    /api/maker-checker/requests              - List requests
 * - GET    /api/maker-checker/requests/{id}         - Get request details
 * - GET    /api/maker-checker/requests/{id}/approvals - Get approval history
 * - POST   /api/maker-checker/requests/{id}/approve - Approve a request
 * - POST   /api/maker-checker/requests/{id}/reject  - Reject a request
 * - POST   /api/maker-checker/requests/{id}/cancel  - Cancel a request
 */
class MakerCheckerRequestController extends Controller
{
    /**
     * List all maker-checker requests.
     *
     * @queryParam status string Filter by status (pending, approved, rejected, etc.)
     * @queryParam type string Filter by type (create, update, delete, execute)
     * @queryParam team_id integer Filter by team ID
     * @queryParam subject_type string Filter by subject model class
     * @queryParam maker_id integer Filter by maker ID
     * @queryParam per_page integer Items per page (default: 15)
     */
    public function index(Request $request): JsonResponse
    {
        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();
        $query = $requestModel::query();

        // Get current user for visibility filtering
        $user = $request->user();
        if ($user instanceof Model) {
            $query->visibleTo($user);
        }

        // Apply filters
        if ($request->filled('status')) {
            $status = RequestStatus::tryFrom($request->string('status')->toString());
            if ($status) {
                $query->where('status', $status);
            }
        }

        if ($request->filled('type')) {
            $type = RequestType::tryFrom($request->string('type')->toString());
            if ($type) {
                $query->where('type', $type);
            }
        }

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->integer('team_id'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->string('subject_type'));
        }

        if ($request->filled('maker_id')) {
            $query->where('maker_id', $request->integer('maker_id'));
        }

        // Sort by latest first
        $query->orderByDesc('created_at');

        $perPage = $request->integer('per_page', 15);
        $requests = $query->paginate($perPage);

        return response()->json([
            'data' => MakerCheckerResource::collection($requests->items())
                ->format(MakerCheckerResource::SIMPLE),
            'meta' => [
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
                'per_page' => $requests->perPage(),
                'total' => $requests->total(),
            ],
        ]);
    }

    /**
     * Get a specific request with full details.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();
        $mcRequest = $requestModel::findOrFail($id);

        // Check visibility
        $user = $request->user();
        if ($user instanceof Model) {
            $visibleIds = $requestModel::query()
                ->visibleTo($user)
                ->where('id', $id)
                ->pluck('id');

            if (!$visibleIds->contains($id)) {
                abort(403, 'You do not have permission to view this request.');
            }
        }

        return response()->json([
            'data' => MakerCheckerResource::make($mcRequest)->format(MakerCheckerResource::BASE),
        ]);
    }

    /**
     * Get approval history for a request.
     */
    public function approvals(Request $request, int $id): JsonResponse
    {
        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();
        $mcRequest = $requestModel::findOrFail($id);

        return response()->json([
            'data' => [
                'required_approvals' => $mcRequest->required_approvals ?? [],
                'current_approvals' => $mcRequest->approvals ?? [],
                'is_fully_approved' => $this->isFullyApproved($mcRequest),
                'pending_roles' => $this->getPendingRoles($mcRequest),
                'pending_users' => $mcRequest->getPendingUsers(),
                'requires_user_approvals' => $mcRequest->requiresUserApprovals(),
            ],
        ]);
    }

    /**
     * Approve a request.
     *
     * @bodyParam role string The role under which to approve
     * @bodyParam remarks string Optional approval remarks
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();
        $mcRequest = $requestModel::findOrFail($id);

        $user = $this->getAuthenticatedUser($request);

        $validated = $request->validate([
            'role' => 'nullable|string',
            'remarks' => 'nullable|string|max:1000',
        ]);

        $role = $validated['role'] ?? $this->getUserRole($user);
        $remarks = $validated['remarks'] ?? null;

        $mcRequest = MakerChecker::approve($mcRequest, $user, $role, $remarks);

        return response()->json([
            'message' => 'Request approved successfully',
            'data' => MakerCheckerResource::make($mcRequest)->format(MakerCheckerResource::BASE),
        ]);
    }

    /**
     * Reject a request.
     *
     * @bodyParam remarks string Optional rejection remarks
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();
        $mcRequest = $requestModel::findOrFail($id);

        $user = $this->getAuthenticatedUser($request);

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:1000',
        ]);

        $mcRequest = MakerChecker::reject($mcRequest, $user, $validated['remarks'] ?? null);

        return response()->json([
            'message' => 'Request rejected successfully',
            'data' => MakerCheckerResource::make($mcRequest)->format(MakerCheckerResource::BASE),
        ]);
    }

    /**
     * Cancel a request (only by the maker).
     *
     * @bodyParam remarks string Optional cancellation remarks
     */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();
        $mcRequest = $requestModel::findOrFail($id);

        $user = $this->getAuthenticatedUser($request);

        $validated = $request->validate([
            'remarks' => 'nullable|string|max:1000',
        ]);

        $mcRequest = MakerChecker::cancel($mcRequest, $user, $validated['remarks'] ?? null);

        return response()->json([
            'message' => 'Request cancelled successfully',
            'data' => MakerCheckerResource::make($mcRequest)->format(MakerCheckerResource::BASE),
        ]);
    }

    /**
     * Get request statistics.
     *
     * @queryParam team_id integer Filter by team ID
     */
    public function statistics(Request $request): JsonResponse
    {
        $requestModel = MakerCheckerServiceProvider::getRequestModelClass();
        $query = $requestModel::query();

        $user = $request->user();
        if ($user instanceof Model) {
            $query->visibleTo($user);
        }

        if ($request->filled('team_id')) {
            $query->where('team_id', $request->integer('team_id'));
        }

        $statusCounts = [];
        foreach (RequestStatus::cases() as $status) {
            $clonedQuery = clone $query;
            $statusCounts[$status->value] = $clonedQuery->where('status', $status)->count();
        }

        $typeCounts = [];
        foreach (RequestType::cases() as $type) {
            $clonedQuery = clone $query;
            $typeCounts[$type->value] = $clonedQuery->where('type', $type)->count();
        }

        return response()->json([
            'data' => [
                'total' => $query->count(),
                'by_status' => $statusCounts,
                'by_type' => $typeCounts,
                'actionable' => $statusCounts[RequestStatus::PENDING->value] + $statusCounts[RequestStatus::PARTIALLY_APPROVED->value],
            ],
        ]);
    }

    /**
     * Get available statuses.
     */
    public function statuses(): JsonResponse
    {
        return response()->json([
            'data' => collect(RequestStatus::cases())->map(fn(RequestStatus $status) => [
                'value' => $status->value,
                'label' => $status->display(),
                'is_actionable' => $status->isActionable(),
                'is_finalized' => $status->isFinalized(),
            ]),
        ]);
    }

    /**
     * Get the authenticated user model.
     */
    protected function getAuthenticatedUser(Request $request): Model
    {
        $user = $request->user();

        if (!$user instanceof Model) {
            abort(401, 'Authentication required');
        }

        return $user;
    }

    /**
     * Get user role for approvals.
     */
    protected function getUserRole(Model $user): ?string
    {
        if ($user instanceof MakerCheckerUserContract) {
            return $user->getMakerCheckerRole();
        }

        if (method_exists($user, 'getMakerCheckerRole')) {
            return $user->getMakerCheckerRole();
        }

        return null;
    }

    /**
     * Check if request is fully approved.
     */
    protected function isFullyApproved(MakerCheckerRequest $request): bool
    {
        return $request->hasMetApprovalThreshold();
    }

    /**
     * Get roles still pending approval.
     *
     * @return array<string, int>
     */
    protected function getPendingRoles(MakerCheckerRequest $request): array
    {
        return $request->getPendingRoles();
    }
}
