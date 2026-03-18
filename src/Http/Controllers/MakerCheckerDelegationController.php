<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Moffhub\MakerChecker\Models\MakerCheckerDelegation;
use Moffhub\MakerChecker\Services\DelegationService;

/**
 * API Controller for managing approval delegations.
 */
class MakerCheckerDelegationController extends Controller
{
    public function __construct(
        protected DelegationService $delegationService,
    ) {}

    /**
     * List delegations for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);

        /** @var \Illuminate\Database\Eloquent\Collection<int, MakerCheckerDelegation> $delegations */
        $delegations = MakerCheckerDelegation::query()
            ->where(function ($query) use ($user) {
                $query->where('delegator_type', $user->getMorphClass())
                    ->where('delegator_id', $user->getKey());
            })
            ->orWhere(function ($query) use ($user) {
                $query->where('delegate_type', $user->getMorphClass())
                    ->where('delegate_id', $user->getKey());
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $delegations->map(fn(MakerCheckerDelegation $d) => [
                'id' => $d->id,
                'delegator_type' => $d->delegator_type,
                'delegator_id' => $d->delegator_id,
                'delegate_type' => $d->delegate_type,
                'delegate_id' => $d->delegate_id,
                'scope' => $d->scope,
                'expires_at' => $d->expires_at?->toIso8601ZuluString(),
                'is_active' => $d->isActive(),
                'created_at' => $d->created_at?->toIso8601ZuluString(),
            ]),
        ]);
    }

    /**
     * Create a new delegation.
     *
     * @bodyParam delegate_id integer Required ID of the delegate user
     * @bodyParam delegate_type string Optional morph type of the delegate (defaults to user morph class)
     * @bodyParam scope string Optional scope restriction
     * @bodyParam expires_at string Optional expiration datetime (ISO 8601)
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'delegate_id' => ['required', 'integer'],
            'delegate_type' => ['nullable', 'string'],
            'scope' => ['nullable', 'string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $delegator = $this->getAuthenticatedUser($request);

        // Resolve delegate model
        $delegateType = $request->input('delegate_type', $delegator->getMorphClass());
        $delegateId = $request->integer('delegate_id');

        if (!class_exists($delegateType)) {
            return response()->json(['message' => 'Invalid delegate type.'], 422);
        }

        $delegate = $delegateType::find($delegateId);

        if (!$delegate instanceof Model) {
            return response()->json(['message' => 'Delegate user not found.'], 404);
        }

        $expiresAt = $request->filled('expires_at') ? Carbon::parse($request->input('expires_at')) : null;

        $delegation = $this->delegationService->create(
            $delegator,
            $delegate,
            $request->input('scope'),
            $expiresAt,
        );

        return response()->json([
            'message' => 'Delegation created successfully.',
            'data' => [
                'id' => $delegation->id,
                'delegator_type' => $delegation->delegator_type,
                'delegator_id' => $delegation->delegator_id,
                'delegate_type' => $delegation->delegate_type,
                'delegate_id' => $delegation->delegate_id,
                'scope' => $delegation->scope,
                'expires_at' => $delegation->expires_at?->toIso8601ZuluString(),
                'is_active' => $delegation->isActive(),
                'created_at' => $delegation->created_at?->toIso8601ZuluString(),
            ],
        ], 201);
    }

    /**
     * Revoke a delegation.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->getAuthenticatedUser($request);

        $delegation = MakerCheckerDelegation::find($id);

        if (!$delegation) {
            return response()->json(['message' => 'Delegation not found.'], 404);
        }

        // Only the delegator can revoke
        if ($delegation->delegator_type !== $user->getMorphClass() || $delegation->delegator_id !== $user->getKey()) {
            return response()->json(['message' => 'You can only revoke your own delegations.'], 403);
        }

        $this->delegationService->revoke($id);

        return response()->json(['message' => 'Delegation revoked successfully.']);
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
}
