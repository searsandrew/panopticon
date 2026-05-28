<?php

use App\Models\CommunicationBlockType;
use App\Models\CustomerCommunicationLog;
use App\Services\NetSuite\NetSuiteCustomerRepository;
use Carbon\CarbonInterface;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    private const COMMUNICATION_LOGS_PAGE = 'communication-logs-page';

    public string $accountNumber = '';

    /**
     * @var array<string, mixed>
     */
    public array $customer = [];

    public function mount(string $accountNumber, NetSuiteCustomerRepository $customers): void
    {
        Gate::authorize('viewAny', CustomerCommunicationLog::class);

        $customer = $customers->findByAccountNumber($accountNumber);

        abort_unless($customer !== null, 404);
        abort_unless($this->userCanAccessCustomer($customer), 403);

        $this->customer = $customer;
        $this->accountNumber = (string) (data_get($customer, 'account_number') ?: $accountNumber);
    }

    #[Computed]
    public function communicationLogs(): LengthAwarePaginator
    {
        return CustomerCommunicationLog::query()
            ->with(['communicationType', 'user', 'blocks.blockType'])
            ->where('netsuite_customer_id', $this->customerId())
            ->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)
            ->orderByDesc('contact_at')
            ->paginate(perPage: 10, pageName: self::COMMUNICATION_LOGS_PAGE);
    }

    #[On('communication-log-saved')]
    public function communicationLogSaved(): void
    {
        $this->resetPage(self::COMMUNICATION_LOGS_PAGE);
    }

    public function customerName(): string
    {
        return (string) (data_get($this->customer, 'companyname') ?: data_get($this->customer, 'entityid') ?: $this->accountNumber);
    }

    public function categoryName(): string
    {
        return (string) (data_get($this->customer, 'category_name') ?: __('Uncategorized'));
    }

    public function cadenceName(): string
    {
        return (string) (data_get($this->customer, 'cadence_name') ?: __('Not set'));
    }

    public function contactAtLabel(CustomerCommunicationLog $log): string
    {
        return $log->contact_at instanceof CarbonInterface
            ? $log->contact_at->format('M j, Y g:i A')
            : __('N/A');
    }

    public function communicationTypeBadgeColor(CustomerCommunicationLog $log): string
    {
        return match ($log->communicationType?->slug) {
            'phone' => 'green',
            'email' => 'blue',
            'text' => 'sky',
            'visit' => 'amber',
            default => 'zinc',
        };
    }

    public function summaryFor(CustomerCommunicationLog $log): string
    {
        $summary = $log->blocks
            ->first(fn ($block): bool => $block->blockType?->slug === CommunicationBlockType::SUMMARY)
            ?->body;

        if (! is_string($summary) || trim($summary) === '') {
            return __('No summary');
        }

        return Str::limit(trim($summary), 180);
    }

    public function render()
    {
        return $this->view()->title($this->customerName());
    }

    /**
     * @param  array<string, mixed>  $customer
     */
    private function userCanAccessCustomer(array $customer): bool
    {
        $netsuiteUserId = Auth::user()?->netsuite_user_id;

        if ($netsuiteUserId === null) {
            return false;
        }

        return collect(['sales_rep_id', 'pipeline_owner_id'])
            ->contains(fn (string $key): bool => data_get($customer, $key) !== null
                && (int) data_get($customer, $key) === (int) $netsuiteUserId);
    }

    private function customerId(): int
    {
        return (int) data_get($this->customer, 'customer_id');
    }
};
?>

<section class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
        <div class="flex flex-col gap-1">
            <flux:heading size="xl">{{ $this->customerName() }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                <flux:badge size="sm" color="zinc">{{ $accountNumber }}</flux:badge>
                <flux:badge size="sm" color="blue">{{ $this->categoryName() }}</flux:badge>
                <flux:badge size="sm" color="emerald">{{ $this->cadenceName() }}</flux:badge>
            </div>
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="space-y-4 lg:col-span-2">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <flux:heading size="lg">{{ __('Communication Logs') }}</flux:heading>
                    <flux:text>{{ trans_choice(':count log|:count logs', $this->communicationLogs->total(), ['count' => $this->communicationLogs->total()]) }}</flux:text>
                </div>

                <livewire:customer-communication-log-flyout
                    :customer="$customer"
                    :account-number="$accountNumber"
                    trigger-label="Add new log"
                    trigger-icon="plus"
                    trigger-size="sm"
                    trigger-variant="primary"
                    :key="'customer-log-flyout-'.$accountNumber"
                />
            </div>

            @if ($this->communicationLogs->total() === 0)
                <div class="rounded-lg border border-neutral-200 p-6 text-sm text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                    {{ __('No communication logs yet.') }}
                </div>
            @else
                <flux:table
                    id="communication-logs"
                    :paginate="$this->communicationLogs"
                    pagination:scroll-to="#communication-logs"
                    container:class="max-h-[36rem]"
                >
                    <flux:table.columns sticky>
                        <flux:table.column>{{ __('Time') }}</flux:table.column>
                        <flux:table.column>{{ __('Type') }}</flux:table.column>
                        <flux:table.column>{{ __('Summary') }}</flux:table.column>
                        <flux:table.column>{{ __('Person') }}</flux:table.column>
                        <flux:table.column>{{ __('User') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->communicationLogs as $log)
                            <flux:table.row :key="'communication-log-'.$log->id">
                                <flux:table.cell class="whitespace-nowrap">
                                    {{ $this->contactAtLabel($log) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom" color="{{ $this->communicationTypeBadgeColor($log) }}">
                                        {{ $log->communicationType?->name ?? __('Unknown') }}
                                    </flux:badge>
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $this->summaryFor($log) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $log->contact_person_name ?: __('N/A') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <flux:badge size="sm" inset="top bottom" color="zinc">
                                        {{ $log->user?->name ?? __('Unknown') }}
                                    </flux:badge>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </section>

        <aside class="space-y-4">
            <flux:card class="space-y-4">
                <flux:heading size="md">{{ __('Customer') }}</flux:heading>

                <dl class="space-y-3 text-sm">
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</dt>
                        <dd class="text-right text-zinc-900 dark:text-zinc-100">{{ data_get($customer, 'email') ?: __('N/A') }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                        <dd class="text-right text-zinc-900 dark:text-zinc-100">{{ data_get($customer, 'phone') ?: __('N/A') }}</dd>
                    </div>
                    <div class="flex items-start justify-between gap-3">
                        <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Sales rep') }}</dt>
                        <dd class="text-right text-zinc-900 dark:text-zinc-100">{{ data_get($customer, 'sales_rep_name') ?: data_get($customer, 'sales_rep_id') }}</dd>
                    </div>
                </dl>
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading size="md">{{ __('Signals') }}</flux:heading>
                <div class="space-y-3">
                    <div class="h-3 rounded-full bg-zinc-100 dark:bg-white/10"></div>
                    <div class="h-3 w-3/4 rounded-full bg-zinc-100 dark:bg-white/10"></div>
                    <div class="h-3 w-1/2 rounded-full bg-zinc-100 dark:bg-white/10"></div>
                </div>
            </flux:card>
        </aside>
    </div>
</section>
