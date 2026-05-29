<?php

namespace App\Policies;

use App\Models\CustomerCommunicationLog;
use App\Models\User;

class CustomerCommunicationLogPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('communication-logs.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, CustomerCommunicationLog $customerCommunicationLog): bool
    {
        return $user->can('communication-logs.view')
            && $this->canAccessLog($user, $customerCommunicationLog);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('communication-logs.create')
            && $user->netsuite_user_id !== null;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, CustomerCommunicationLog $customerCommunicationLog): bool
    {
        return $user->can('communication-logs.update')
            && $this->canAccessLog($user, $customerCommunicationLog);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, CustomerCommunicationLog $customerCommunicationLog): bool
    {
        return $user->can('communication-logs.delete')
            && $this->canAccessLog($user, $customerCommunicationLog);
    }

    /**
     * Determine whether the user can view audit history for the model.
     */
    public function viewAuditHistory(User $user, CustomerCommunicationLog $customerCommunicationLog): bool
    {
        return $user->can('communication-logs.view-history')
            && $this->canAccessLog($user, $customerCommunicationLog);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, CustomerCommunicationLog $customerCommunicationLog): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, CustomerCommunicationLog $customerCommunicationLog): bool
    {
        return false;
    }

    private function canAccessLog(User $user, CustomerCommunicationLog $customerCommunicationLog): bool
    {
        if ($customerCommunicationLog->isDraft()) {
            return $customerCommunicationLog->user_id === $user->id;
        }

        $customerOwnerIds = collect([
            $customerCommunicationLog->netsuite_customer_sales_rep_id,
            $customerCommunicationLog->netsuite_customer_pipeline_owner_id,
        ])->filter(fn (mixed $salesRepId): bool => is_numeric($salesRepId) && (int) $salesRepId > 0);

        if ($customerOwnerIds->isNotEmpty()) {
            return $customerOwnerIds
                ->contains(fn (mixed $salesRepId): bool => $user->canAccessNetSuiteSalesRep($salesRepId));
        }

        return $customerCommunicationLog->netsuite_sales_rep_id !== null
            && $user->canAccessNetSuiteSalesRep($customerCommunicationLog->netsuite_sales_rep_id);
    }
}
