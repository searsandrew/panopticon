<?php

use App\Services\NetSuite\NetSuiteCustomerRepository;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function activeCustomers(): array
    {
        $salesRepId = $this->salesRepId();

        if ($salesRepId === null) {
            return [];
        }

        return app(NetSuiteCustomerRepository::class)->activeForSalesRep($salesRepId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function pipelineCustomers(): array
    {
        $salesRepId = $this->salesRepId();

        if ($salesRepId === null) {
            return [];
        }

        return app(NetSuiteCustomerRepository::class)->pipelineForSalesRep($salesRepId);
    }

    #[Computed]
    public function isLinkedToNetSuite(): bool
    {
        return $this->salesRepId() !== null;
    }

    private function salesRepId(): ?int
    {
        $netsuiteUserId = Auth::user()?->netsuite_user_id;

        return is_int($netsuiteUserId) ? $netsuiteUserId : null;
    }
};
?>

<section class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Dashboard') }}</flux:heading>
        <flux:text>{{ __('Pipeline focus and active customer cadence.') }}</flux:text>
    </div>

    @if (! $this->isLinkedToNetSuite)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-100">
            {{ __('Your account is verified, but it is not linked to a NetSuite employee yet. Log out and back in to retry linking, or ask an administrator to set your NetSuite user ID.') }}
        </div>
    @else
        <section class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between gap-3">
                <flux:heading size="lg">{{ __('Pipeline') }}</flux:heading>
                <flux:text>{{ trans_choice(':count prospect|:count prospects', count($this->pipelineCustomers), ['count' => count($this->pipelineCustomers)]) }}</flux:text>
            </div>

            @if ($this->pipelineCustomers === [])
                <div class="rounded-lg border border-neutral-200 p-5 text-sm text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                    {{ __('No prospective customers are assigned to your pipeline right now.') }}
                </div>
            @else
                <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    @foreach ($this->pipelineCustomers as $customer)
                        <article wire:key="pipeline-customer-{{ data_get($customer, 'customer_id') }}" class="rounded-lg border border-neutral-200 bg-white p-4 shadow-sm dark:border-neutral-700 dark:bg-neutral-950">
                            <div class="flex flex-col gap-3">
                                <div>
                                    <flux:heading size="md">{{ data_get($customer, 'companyname') ?: data_get($customer, 'entityid') }}</flux:heading>
                                    <flux:text>{{ data_get($customer, 'email') ?: __('No email on file') }}</flux:text>
                                </div>

                                <div class="grid gap-3 text-sm sm:grid-cols-3">
                                    <div>
                                        <div class="text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{{ __('Phone') }}</div>
                                        <div class="mt-1 text-neutral-900 dark:text-neutral-100">{{ data_get($customer, 'phone') ?: __('N/A') }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{{ __('Cadence') }}</div>
                                        <div class="mt-1 text-neutral-900 dark:text-neutral-100">{{ data_get($customer, 'cadence_name') ?: __('Not set') }}</div>
                                    </div>
                                    <div>
                                        <div class="text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{{ __('Duration') }}</div>
                                        <div class="mt-1 font-mono text-xs text-neutral-900 dark:text-neutral-100">{{ data_get($customer, 'cadence_iso8601') ?: __('N/A') }}</div>
                                    </div>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>

        <section class="flex flex-col gap-3">
            <div class="flex items-baseline justify-between gap-3">
                <flux:heading size="lg">{{ __('Active Customers') }}</flux:heading>
                <flux:text>{{ trans_choice(':count customer|:count customers', count($this->activeCustomers), ['count' => count($this->activeCustomers)]) }}</flux:text>
            </div>

            @if ($this->activeCustomers === [])
                <div class="rounded-lg border border-neutral-200 p-6 text-sm text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                    {{ __('No active customers are assigned to your NetSuite sales rep account yet.') }}
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                            <thead class="bg-neutral-50 text-left text-xs font-medium uppercase text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                                <tr>
                                    <th scope="col" class="px-4 py-3">{{ __('Customer') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Email') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Phone') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Cadence') }}</th>
                                    <th scope="col" class="px-4 py-3">{{ __('Duration') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-neutral-200 bg-white dark:divide-neutral-800 dark:bg-neutral-950">
                                @foreach ($this->activeCustomers as $customer)
                                    <tr wire:key="active-customer-{{ data_get($customer, 'customer_id') }}">
                                        <td class="px-4 py-3 font-medium text-neutral-900 dark:text-neutral-100">
                                            {{ data_get($customer, 'companyname') ?: data_get($customer, 'entityid') }}
                                        </td>
                                        <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">
                                            {{ data_get($customer, 'email') ?: __('N/A') }}
                                        </td>
                                        <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">
                                            {{ data_get($customer, 'phone') ?: __('N/A') }}
                                        </td>
                                        <td class="px-4 py-3 text-neutral-600 dark:text-neutral-300">
                                            {{ data_get($customer, 'cadence_name') ?: __('Not set') }}
                                        </td>
                                        <td class="px-4 py-3 font-mono text-xs text-neutral-600 dark:text-neutral-300">
                                            {{ data_get($customer, 'cadence_iso8601') ?: __('N/A') }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    @endif
</section>
