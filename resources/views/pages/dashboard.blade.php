<?php

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
        $salesRepId = $this->salesRepId();

        if ($salesRepId === null) {
            return;
        }

        $this->pipelineCustomerRows = $customers->pipelineForSalesRep($salesRepId);
        $this->activeCustomerRows = $customers->activeForSalesRep($salesRepId);
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
        $customers = collect($this->activeCustomers)
            ->sortBy(
                fn (array $customer): string => $this->activeCustomerSortValue($customer, $this->activeCustomerSortColumn),
                SORT_NATURAL | SORT_FLAG_CASE,
                $this->activeCustomerSortDirection === 'desc',
            )
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
        $now = now();

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
        $now = now();

        if ($dueAt->lessThan($now->copy()->subMinute())) {
            return 'red';
        }

        if ($dueAt->lessThanOrEqualTo($now->copy()->addDays($this->contactDueWarningDays()))) {
            return 'yellow';
        }

        return 'green';
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
            return now();
        }

        $cadenceIso8601 = data_get($customer, 'cadence_iso8601');

        if (! is_string($cadenceIso8601) || trim($cadenceIso8601) === '') {
            return now();
        }

        try {
            return $lastContactAt->copy()->add(new \DateInterval($cadenceIso8601));
        } catch (\Throwable) {
            return now();
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
                return $value->copy();
            }

            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value);
            }

            if (is_int($value)) {
                return Carbon::createFromTimestamp($value);
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    return Carbon::parse($value);
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
                        <flux:card wire:key="pipeline-customer-{{ data_get($customer, 'customer_id') }}" class="space-y-4">
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
                                    <div class="text-xs font-medium uppercase text-neutral-500 dark:text-neutral-400">{{ __('Log') }}</div>
                                    <flux:button.group class="mt-1">
                                        <flux:button size="sm" icon="chat-bubble-left-ellipsis" type="button">{{ __('Log') }}</flux:button>
                                        <flux:button size="sm" icon="plus" type="button" :aria-label="__('Add log entry')" />
                                    </flux:button.group>
                                </div>
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
                            @php($activeCustomerHref = route('customers.show', ['customer' => data_get($customer, 'customer_id')]))

                            <flux:table.row :key="'active-customer-'.data_get($customer, 'customer_id')" class="group cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5">
                                <flux:table.cell variant="strong">
                                    <a href="{{ $activeCustomerHref }}" wire:navigate class="-m-3 block px-3 py-3">
                                        <span class="flex items-center gap-2">
                                            <flux:badge size="sm" inset="top bottom" color="{{ $this->activeCustomerCategoryBadgeColor($customer) }}">
                                                {{ $this->activeCustomerCategoryLabel($customer) }}
                                            </flux:badge>

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
