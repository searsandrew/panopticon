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

        if ($user->netsuite_user_id !== null) {
            return;
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            return;
        }

        $netsuiteUserId = $this->employees->resolveIdByEmail($user->email);

        if ($netsuiteUserId === null) {
            return;
        }

        $user->forceFill([
            'netsuite_user_id' => $netsuiteUserId,
        ])->save();
        $user->assignRole('sales-rep');
    }
}
