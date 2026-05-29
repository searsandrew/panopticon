<?php

use App\Models\CustomerCommunicationLog;
use App\Services\NetSuite\NetSuiteCustomerRepository;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Dashboard')] class extends Component {
    use WithPagination;

    private const ACTIVE_CUSTOMERS_PAGE = 'active-customers-page';

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $activeCustomerRows = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $pipelineCustomerRows = [];

    public string $activeCustomerSortColumn = 'customer';

    public string $activeCustomerSortDirection = 'asc';

    public int $activeCustomersPerPage = 10;

    public function mount(NetSuiteCustomerRepository $customers): void
    {
        $salesRepIds = $this->salesRepScopeIds();

        if ($salesRepIds === []) {
            return;
        }

        $this->pipelineCustomerRows = $this->withCommunicationLogStats(
            $customers->pipelineForSalesRepScope($salesRepIds),
            includeDraftFollowUps: false,
        );
        $this->activeCustomerRows = $this->withLastCommunicationLogs($customers->activeForSalesRepScope($salesRepIds));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function activeCustomers(): array
    {
        return $this->activeCustomerRows;
    }

    #[Computed]
    public function paginatedActiveCustomers(): LengthAwarePaginator
    {
        $sortedCustomers = collect($this->activeCustomers)
            ->sortBy(
                fn (array $customer): string => $this->activeCustomerSortValue($customer, $this->activeCustomerSortColumn),
                SORT_NATURAL | SORT_FLAG_CASE,
                $this->activeCustomerSortDirection === 'desc',
            )
            ->values();
        $partitionedCustomers = $sortedCustomers->partition(
            fn (array $customer): bool => $this->activeCustomerRequiresFollowUp($customer),
        );
        $customers = $partitionedCustomers[0]
            ->concat($partitionedCustomers[1])
            ->values();

        $page = $this->getPage(self::ACTIVE_CUSTOMERS_PAGE);
        $items = $customers
            ->slice(($page - 1) * $this->activeCustomersPerPage, $this->activeCustomersPerPage)
            ->values();

        return new LengthAwarePaginator(
            $items,
            $customers->count(),
            $this->activeCustomersPerPage,
            $page,
            [
                'pageName' => self::ACTIVE_CUSTOMERS_PAGE,
                'path' => request()->url(),
            ],
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function pipelineCustomers(): array
    {
        return $this->pipelineCustomerRows;
    }

    public function sortActiveCustomers(string $column): void
    {
        if (! in_array($column, ['customer', 'email', 'phone', 'cadence', 'contact_due'], true)) {
            return;
        }

        if ($this->activeCustomerSortColumn === $column) {
            $this->activeCustomerSortDirection = $this->activeCustomerSortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->activeCustomerSortColumn = $column;
            $this->activeCustomerSortDirection = 'asc';
        }

        $this->resetPage(self::ACTIVE_CUSTOMERS_PAGE);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function activeCustomerCategoryLabel(array $customer): string
    {
        $categoryName = data_get($customer, 'category_name');

        if (is_string($categoryName) && trim($categoryName) !== '') {
            return trim($categoryName);
        }

        return __('Uncategorized');
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function activeCustomerCategoryBadgeColor(array $customer): string
    {
        return match (strtoupper($this->activeCustomerCategoryLabel($customer))) {
            'APW1' => 'blue',
            'APW2' => 'sky',
            'APW3' => 'indigo',
            'COMMERCIAL' => 'emerald',
            'COMMERCIAL SERVICE' => 'teal',
            'EXTENDED WARRANTY' => 'amber',
            'OEM' => 'purple',
            default => 'zinc',
        };
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function activeCustomerContactDueLabel(array $customer): string
    {
        if ($this->activeCustomerLastContactAt($customer) === null) {
            return __('Due now');
        }

        $dueAt = $this->activeCustomerContactDueAt($customer);
        $now = now($this->userTimezone());

        if (
            $dueAt->greaterThanOrEqualTo($now->copy()->subMinute())
            && $dueAt->lessThanOrEqualTo($now->copy()->addMinute())
        ) {
            return __('Due now');
        }

        $diff = $dueAt->diffForHumans($now, [
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'parts' => 1,
        ]);

        if ($dueAt->lessThan($now)) {
            return __('Overdue by :time', ['time' => $diff]);
        }

        return __('Due in :time', ['time' => $diff]);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function activeCustomerContactDueBadgeColor(array $customer): string
    {
        $dueAt = $this->activeCustomerContactDueAt($customer);
        $now = now($this->userTimezone());

        if ($dueAt->lessThan($now->copy()->subMinute())) {
            return 'red';
        }

        if ($dueAt->lessThanOrEqualTo($now->copy()->addDays($this->contactDueWarningDays()))) {
            return 'yellow';
        }

        return 'green';
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function activeCustomerRequiresFollowUp(array $customer): bool
    {
        return (bool) data_get($customer, 'requires_follow_up', false);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function activeCustomerFollowUpLabel(array $customer): string
    {
        $count = (int) data_get($customer, 'follow_up_count', 0);

        return trans_choice(':count follow-up|:count follow-ups', $count, ['count' => $count]);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function activeCustomerRowClass(array $customer): string
    {
        $classes = 'group cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5';

        if ($this->activeCustomerRequiresFollowUp($customer)) {
            $classes .= ' bg-amber-50/70 hover:bg-amber-100/70 dark:bg-amber-500/10 dark:hover:bg-amber-500/15';
        }

        return $classes;
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function pipelineCustomerSubmittedLogCount(array $customer): int
    {
        return (int) data_get($customer, 'submitted_log_count', 0);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function pipelineCustomerLogCountLabel(array $customer): string
    {
        $count = $this->pipelineCustomerSubmittedLogCount($customer);

        return trans_choice(':count log|:count logs', $count, ['count' => $count]);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function pipelineCustomerRequiresFollowUp(array $customer): bool
    {
        return (bool) data_get($customer, 'requires_follow_up', false);
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    public function pipelineCustomerLogButtonIcon(array $customer): ?string
    {
        return $this->pipelineCustomerRequiresFollowUp($customer) ? 'flag' : null;
    }

    #[Computed]
    public function isLinkedToNetSuite(): bool
    {
        return $this->salesRepScopeIds() !== [];
    }

    /**
     * @return array<int, int>
     */
    private function salesRepScopeIds(): array
    {
        $user = Auth::user();

        return $user instanceof \App\Models\User ? $user->netsuiteSalesRepScopeIds() : [];
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function activeCustomerSortValue(array $customer, string $column): string
    {
        return match ($column) {
            'email' => (string) (data_get($customer, 'email') ?: ''),
            'phone' => (string) (data_get($customer, 'phone') ?: ''),
            'cadence' => (string) (data_get($customer, 'cadence_name') ?: ''),
            'contact_due' => (string) $this->activeCustomerContactDueAt($customer)->getTimestamp(),
            default => (string) (data_get($customer, 'companyname') ?: data_get($customer, 'entityid') ?: ''),
        };
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function activeCustomerContactDueAt(array $customer): CarbonInterface
    {
        $lastContactAt = $this->activeCustomerLastContactAt($customer);

        if ($lastContactAt === null) {
            return now($this->userTimezone());
        }

        $cadenceIso8601 = data_get($customer, 'cadence_iso8601');

        if (! is_string($cadenceIso8601) || trim($cadenceIso8601) === '') {
            return now($this->userTimezone());
        }

        try {
            return $lastContactAt->copy()->add(new \DateInterval($cadenceIso8601));
        } catch (\Throwable) {
            return now($this->userTimezone());
        }
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function activeCustomerLastContactAt(array $customer): ?CarbonInterface
    {
        foreach (['last_log_at', 'last_contact_at', 'last_contacted_at', 'last_communication_at'] as $key) {
            $value = data_get($customer, $key);

            if ($value instanceof Carbon) {
                return $value->copy()->timezone($this->userTimezone());
            }

            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)->timezone($this->userTimezone());
            }

            if (is_int($value)) {
                return Carbon::createFromTimestamp($value)->timezone($this->userTimezone());
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    return Carbon::parse($value)->timezone($this->userTimezone());
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    private function contactDueWarningDays(): int
    {
        return max(0, (int) config('panopticon.contact_due_warning_days', 2));
    }

    private function userTimezone(): string
    {
        $timezone = Auth::user()?->timezone;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }

    /**
     * @param  array<int, array<string, mixed>>  $customers
     * @return array<int, array<string, mixed>>
     */
    private function withLastCommunicationLogs(array $customers): array
    {
        return $this->withCommunicationLogStats($customers, includeLastLog: true);
    }

    /**
     * @param  array<int, array<string, mixed>>  $customers
     * @return array<int, array<string, mixed>>
     */
    private function withCommunicationLogStats(
        array $customers,
        bool $includeLastLog = false,
        bool $includeDraftFollowUps = true,
    ): array
    {
        $customerIds = collect($customers)
            ->pluck('customer_id')
            ->filter()
            ->map(fn ($customerId): int => (int) $customerId)
            ->unique()
            ->values();

        if ($customerIds->isEmpty()) {
            return $customers;
        }

        $lastLogs = collect();

        if ($includeLastLog) {
            $lastLogs = CustomerCommunicationLog::query()
                ->whereIn('netsuite_customer_id', $customerIds)
                ->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)
                ->selectRaw('netsuite_customer_id, max(contact_at) as last_log_at')
                ->groupBy('netsuite_customer_id')
                ->pluck('last_log_at', 'netsuite_customer_id');
        }

        $submittedLogCounts = CustomerCommunicationLog::query()
            ->whereIn('netsuite_customer_id', $customerIds)
            ->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)
            ->selectRaw('netsuite_customer_id, count(*) as submitted_log_count')
            ->groupBy('netsuite_customer_id')
            ->pluck('submitted_log_count', 'netsuite_customer_id');

        $followUpCounts = CustomerCommunicationLog::query()
            ->whereIn('netsuite_customer_id', $customerIds)
            ->where('requires_follow_up', true)
            ->where(function ($query) use ($includeDraftFollowUps): void {
                $query->where(function ($query): void {
                    $query
                        ->where('status', CustomerCommunicationLog::STATUS_SUBMITTED);
                })->when($includeDraftFollowUps, function ($query): void {
                    $query->orWhere(function ($query): void {
                        $query
                            ->where('status', CustomerCommunicationLog::STATUS_DRAFT)
                            ->where('user_id', Auth::id());
                    });
                });
            })
            ->selectRaw('netsuite_customer_id, count(*) as follow_up_count')
            ->groupBy('netsuite_customer_id')
            ->pluck('follow_up_count', 'netsuite_customer_id');

        return collect($customers)
            ->map(function (array $customer) use ($followUpCounts, $lastLogs, $submittedLogCounts): array {
                $customerId = (int) data_get($customer, 'customer_id');
                $lastLogAt = $lastLogs->get($customerId);
                $followUpCount = (int) $followUpCounts->get($customerId, 0);
                $submittedLogCount = (int) $submittedLogCounts->get($customerId, 0);

                if ($lastLogAt !== null) {
                    $customer['last_log_at'] = $lastLogAt;
                }

                $customer['submitted_log_count'] = $submittedLogCount;
                $customer['follow_up_count'] = $followUpCount;
                $customer['requires_follow_up'] = $followUpCount > 0;

                return $customer;
            })
            ->all();
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
                        <flux:card wire:key="pipeline-customer-{{ data_get($customer, 'customer_id') }}" class="flex h-full flex-col justify-between gap-4">
                            <div class="space-y-2">
                                <flux:heading size="md">{{ data_get($customer, 'companyname') ?: data_get($customer, 'entityid') }}</flux:heading>
                                <div class="space-y-1">
                                    <flux:text>{{ data_get($customer, 'email') ?: __('No email on file') }}</flux:text>
                                    <flux:text>{{ data_get($customer, 'phone') ?: __('No phone on file') }}</flux:text>
                                </div>
                            </div>

                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <livewire:customer-communication-log-list
                                    :customer="$customer"
                                    :account-number="(string) (data_get($customer, 'account_number') ?: data_get($customer, 'customer_id'))"
                                    :trigger-label="$this->pipelineCustomerLogCountLabel($customer)"
                                    :trigger-icon="$this->pipelineCustomerLogButtonIcon($customer)"
                                    trigger-size="sm"
                                    :trigger-variant="$this->pipelineCustomerRequiresFollowUp($customer) ? 'filled' : ''"
                                    :key="'pipeline-log-list-'.data_get($customer, 'customer_id')"
                                />
                                <livewire:customer-communication-log-flyout
                                    :customer="$customer"
                                    :account-number="(string) (data_get($customer, 'account_number') ?: data_get($customer, 'customer_id'))"
                                    trigger-label="Add log"
                                    trigger-icon="plus"
                                    trigger-size="sm"
                                    trigger-variant=""
                                    :key="'pipeline-log-flyout-'.data_get($customer, 'customer_id')"
                                />
                            </div>
                        </flux:card>
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
                <flux:table
                    id="active-customers"
                    :paginate="$this->paginatedActiveCustomers"
                    pagination:scroll-to="#active-customers"
                    container:class="max-h-[32rem]"
                >
                    <flux:table.columns sticky>
                        <flux:table.column
                            sortable
                            :sorted="$this->activeCustomerSortColumn === 'customer'"
                            :direction="$this->activeCustomerSortDirection"
                            wire:click="sortActiveCustomers('customer')"
                        >
                            {{ __('Customer') }}
                        </flux:table.column>
                        <flux:table.column
                            sortable
                            :sorted="$this->activeCustomerSortColumn === 'email'"
                            :direction="$this->activeCustomerSortDirection"
                            wire:click="sortActiveCustomers('email')"
                        >
                            {{ __('Email') }}
                        </flux:table.column>
                        <flux:table.column
                            sortable
                            :sorted="$this->activeCustomerSortColumn === 'phone'"
                            :direction="$this->activeCustomerSortDirection"
                            wire:click="sortActiveCustomers('phone')"
                        >
                            {{ __('Phone') }}
                        </flux:table.column>
                        <flux:table.column
                            sortable
                            :sorted="$this->activeCustomerSortColumn === 'cadence'"
                            :direction="$this->activeCustomerSortDirection"
                            wire:click="sortActiveCustomers('cadence')"
                        >
                            {{ __('Cadence') }}
                        </flux:table.column>
                        <flux:table.column
                            sortable
                            :sorted="$this->activeCustomerSortColumn === 'contact_due'"
                            :direction="$this->activeCustomerSortDirection"
                            wire:click="sortActiveCustomers('contact_due')"
                        >
                            {{ __('Contact Due') }}
                        </flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->paginatedActiveCustomers as $customer)
                            @php($activeCustomerAccountNumber = data_get($customer, 'account_number') ?: data_get($customer, 'customer_id'))
                            @php($activeCustomerHref = route('customers.show', ['accountNumber' => $activeCustomerAccountNumber]))

                            <flux:table.row :key="'active-customer-'.data_get($customer, 'customer_id')" class="{{ $this->activeCustomerRowClass($customer) }}">
                                <flux:table.cell variant="strong">
                                    <a href="{{ $activeCustomerHref }}" wire:navigate class="-m-3 block px-3 py-3">
                                        <span class="flex flex-wrap items-center gap-2">
                                            <flux:badge size="sm" inset="top bottom" color="{{ $this->activeCustomerCategoryBadgeColor($customer) }}">
                                                {{ $this->activeCustomerCategoryLabel($customer) }}
                                            </flux:badge>

                                            @if ($this->activeCustomerRequiresFollowUp($customer))
                                                <flux:badge size="sm" inset="top bottom" color="amber" icon="flag">
                                                    {{ $this->activeCustomerFollowUpLabel($customer) }}
                                                </flux:badge>
                                            @endif

                                            <span>{{ data_get($customer, 'companyname') ?: data_get($customer, 'entityid') }}</span>
                                        </span>
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <a href="{{ $activeCustomerHref }}" wire:navigate class="-m-3 block px-3 py-3">
                                        {{ data_get($customer, 'email') ?: __('N/A') }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <a href="{{ $activeCustomerHref }}" wire:navigate class="-m-3 block px-3 py-3">
                                        {{ data_get($customer, 'phone') ?: __('N/A') }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <a href="{{ $activeCustomerHref }}" wire:navigate class="-m-3 block px-3 py-3">
                                        {{ data_get($customer, 'cadence_name') ?: __('Not set') }}
                                    </a>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <a href="{{ $activeCustomerHref }}" wire:navigate class="-m-3 block px-3 py-3">
                                        <flux:badge size="sm" inset="top bottom" color="{{ $this->activeCustomerContactDueBadgeColor($customer) }}">
                                            {{ $this->activeCustomerContactDueLabel($customer) }}
                                        </flux:badge>
                                    </a>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </section>
    @endif
</section>
