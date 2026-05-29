<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\NetSuite\NetSuiteEmployeeResolver;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class LinkUserToNetSuite
{
    /**
     * Create the event listener.
     */
    public function __construct(
        private readonly NetSuiteEmployeeResolver $employees,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(Login|Verified $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $user = $event->user;

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return;
        }

        if ($user->netsuite_user_id !== null) {
            $managedSalesRepIds = $this->employees->managedSalesRepIdsForEmployee($user->netsuite_user_id);

            if ($managedSalesRepIds !== null) {
                $user->forceFill([
                    'netsuite_managed_sales_rep_ids' => $managedSalesRepIds,
                ])->save();
            }

            return;
        }

        $profile = $this->employees->resolveByEmail($user->email);

        if ($profile === null) {
            return;
        }

        $user->forceFill([
            'netsuite_user_id' => $profile['id'],
            'netsuite_managed_sales_rep_ids' => $profile['managed_sales_rep_ids'],
        ])->save();

        $user->assignRole('sales-rep');
    }
}
