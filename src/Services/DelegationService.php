<?php

declare(strict_types=1);

namespace Moffhub\MakerChecker\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Moffhub\MakerChecker\Events\ApprovalDelegated;
use Moffhub\MakerChecker\Models\MakerCheckerDelegation;

class DelegationService
{
    /**
     * Create a new delegation.
     */
    public function create(
        Model $delegator,
        Model $delegate,
        ?string $scope = null,
        ?Carbon $expiresAt = null,
    ): MakerCheckerDelegation {
        $delegation = MakerCheckerDelegation::create([
            'delegator_type' => $delegator->getMorphClass(),
            'delegator_id' => $delegator->getKey(),
            'delegate_type' => $delegate->getMorphClass(),
            'delegate_id' => $delegate->getKey(),
            'scope' => $scope,
            'expires_at' => $expiresAt,
        ]);

        event(ApprovalDelegated::fromDelegation($delegation));

        return $delegation;
    }

    /**
     * Revoke a delegation by ID.
     */
    public function revoke(int $id): bool
    {
        $delegation = MakerCheckerDelegation::find($id);

        if (!$delegation) {
            return false;
        }

        return $delegation->delete() ?? false;
    }

    /**
     * Get active delegations for a delegator.
     *
     * @return Collection<int, MakerCheckerDelegation>
     */
    public function getActiveDelegationsFor(Model $delegator): Collection
    {
        return MakerCheckerDelegation::query()
            ->where('delegator_type', $delegator->getMorphClass())
            ->where('delegator_id', $delegator->getKey())
            ->active()
            ->get();
    }

    /**
     * Get all active delegations where the given user is a delegate.
     *
     * @return Collection<int, MakerCheckerDelegation>
     */
    public function getActiveDelegationsAsDelegate(Model $delegate): Collection
    {
        return MakerCheckerDelegation::query()
            ->where('delegate_type', $delegate->getMorphClass())
            ->where('delegate_id', $delegate->getKey())
            ->active()
            ->get();
    }

    /**
     * Get delegates for a given set of approvers (resolve delegations).
     *
     * @param  Collection<int, Model>  $approvers
     * @return Collection<int, Model>
     */
    public function getDelegatesForApprovers(Collection $approvers): Collection
    {
        $delegates = collect();

        foreach ($approvers as $approver) {
            $activeDelegations = $this->getActiveDelegationsFor($approver);

            foreach ($activeDelegations as $delegation) {
                $delegate = $delegation->delegate;
                if ($delegate) {
                    $delegates->push($delegate);
                }
            }
        }

        return $delegates;
    }
}
