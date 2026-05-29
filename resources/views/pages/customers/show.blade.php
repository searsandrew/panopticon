<?php

use App\Models\CommunicationBlockType;
use App\Models\CommunicationType;
use App\Models\CustomerCommunicationLog;
use App\Models\CustomerCommunicationLogBlock;
use App\Services\NetSuite\NetSuiteCustomerRepository;
use App\Services\NetSuite\NetSuiteCustomerInsightsRepository;
use Carbon\CarbonInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use OwenIt\Auditing\Models\Audit;

new class extends Component {
    use WithPagination;

    private const COMMUNICATION_LOGS_PAGE = 'communication-logs-page';

    private const VISIBLE_AUDIT_ATTRIBUTES = [
        'communication_type_id',
        'communication_block_type_id',
        'contact_person_name',
        'contact_at',
        'status',
        'requires_follow_up',
        'submitted_at',
        'body',
    ];

    public string $accountNumber = '';

    /**
     * @var array<string, mixed>
     */
    public array $customer = [];

    public bool $showLogDetails = false;

    public bool $showLogHistory = false;

    public ?string $selectedLogId = null;

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
            ->where(function ($query): void {
                $query->where('status', CustomerCommunicationLog::STATUS_SUBMITTED)
                    ->orWhere(function ($query): void {
                        $query
                            ->where('status', CustomerCommunicationLog::STATUS_DRAFT)
                            ->where('user_id', Auth::id());
                    });
            })
            ->orderByDesc('requires_follow_up')
            ->orderByDesc('updated_at')
            ->orderByDesc('contact_at')
            ->paginate(perPage: 10, pageName: self::COMMUNICATION_LOGS_PAGE);
    }

    #[On('communication-log-saved')]
    public function communicationLogSaved(): void
    {
        unset($this->communicationLogs);

        $this->resetPage(self::COMMUNICATION_LOGS_PAGE);
    }

    public function viewLog(string $logId): void
    {
        $log = $this->findLogForCurrentCustomer($logId);

        if ($log->isDraft()) {
            $this->editLog($log->id);

            return;
        }

        Gate::authorize('view', $log);

        $this->selectedLogId = $log->id;
        $this->showLogDetails = true;
    }

    public function editLog(string $logId): void
    {
        $log = $this->findLogForCurrentCustomer($logId);

        Gate::authorize('update', $log);

        $this->selectedLogId = null;
        $this->showLogDetails = false;
        $this->showLogHistory = false;

        $this->dispatch('open-communication-log-editor', logId: $log->id);
    }

    public function viewLogHistory(string $logId): void
    {
        $log = $this->findLogForCurrentCustomer($logId);

        Gate::authorize('viewAuditHistory', $log);

        $this->selectedLogId = $log->id;
        $this->showLogDetails = false;
        $this->showLogHistory = true;
    }

    public function viewSelectedLogDetails(): void
    {
        if ($this->selectedLogId === null) {
            return;
        }

        $log = $this->findLogForCurrentCustomer($this->selectedLogId);

        Gate::authorize('view', $log);

        $this->showLogHistory = false;
        $this->showLogDetails = true;
    }

    public function toggleFollowUp(string $logId): void
    {
        $log = $this->findLogForCurrentCustomer($logId);

        Gate::authorize('update', $log);

        $log->forceFill([
            'requires_follow_up' => ! $log->requires_follow_up,
        ])->save();

        unset($this->communicationLogs);

        $this->selectedLogId = $log->id;

        $this->dispatch('communication-log-saved');
    }

    public function deleteDraft(string $logId): void
    {
        $log = $this->findLogForCurrentCustomer($logId);

        abort_unless($log->isDraft(), 403);

        Gate::authorize('delete', $log);

        $log->delete();

        unset($this->communicationLogs);

        if ($this->selectedLogId === $log->id) {
            $this->selectedLogId = null;
            $this->showLogDetails = false;
            $this->showLogHistory = false;
        }

        $this->dispatch('communication-log-saved');
    }

    public function updatedShowLogDetails(bool $value): void
    {
        if (! $value && ! $this->showLogHistory) {
            $this->selectedLogId = null;
        }
    }

    public function updatedShowLogHistory(bool $value): void
    {
        if (! $value && ! $this->showLogDetails) {
            $this->selectedLogId = null;
        }
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

    /**
     * @return array<int, array{period: string, label: string, amount: float}>
     */
    #[Computed]
    public function purchaseHistory(): array
    {
        return app(NetSuiteCustomerInsightsRepository::class)
            ->purchaseHistory($this->customerId());
    }

    /**
     * @return array<int, array{item_id: int|null, itemid: string, name: string, released_at: string|null}>
     */
    #[Computed]
    public function newProductGaps(): array
    {
        return app(NetSuiteCustomerInsightsRepository::class)
            ->newlyReleasedItemsNotPurchased($this->customerId());
    }

    public function purchaseHistoryTotalLabel(): string
    {
        $total = collect($this->purchaseHistory)->sum('amount');

        return $this->moneyLabel($total);
    }

    /**
     * @param  array{period: string, label: string, amount: float}  $point
     */
    public function purchaseHistoryBarHeight(array $point): string
    {
        $max = max(collect($this->purchaseHistory)->max('amount') ?: 0, 1);
        $height = max(8, (int) round(((float) $point['amount'] / $max) * 100));

        return $height.'%';
    }

    public function moneyLabel(float|int|string|null $amount): string
    {
        return '$'.number_format((float) $amount, 0);
    }

    public function releasedAtLabel(?string $date): string
    {
        if ($date === null || trim($date) === '') {
            return __('Recently released');
        }

        try {
            return CarbonImmutable::parse($date)->format('M j, Y');
        } catch (Throwable) {
            return $date;
        }
    }

    public function contactAtLabel(CustomerCommunicationLog $log): string
    {
        return $log->contact_at instanceof CarbonInterface
            ? $log->contact_at->copy()->timezone($this->userTimezone())->format('M j, g:i A')
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

    public function statusBadgeColor(CustomerCommunicationLog $log): string
    {
        return $log->isDraft() ? 'zinc' : 'emerald';
    }

    public function statusLabel(CustomerCommunicationLog $log): string
    {
        return $log->isDraft() ? __('Draft') : __('Submitted');
    }

    public function followUpButtonLabel(CustomerCommunicationLog $log): string
    {
        return $log->requires_follow_up ? __('Follow-up flagged') : __('Flag follow-up');
    }

    public function followUpButtonVariant(CustomerCommunicationLog $log): string
    {
        return $log->requires_follow_up ? 'filled' : 'ghost';
    }

    public function logRowClass(CustomerCommunicationLog $log): string
    {
        $classes = 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5';

        if ($log->requires_follow_up) {
            $classes .= ' bg-amber-50/70 hover:bg-amber-100/70 dark:bg-amber-500/10 dark:hover:bg-amber-500/15';
        }

        return $classes;
    }

    public function blockTypeBadgeColor(?string $slug): string
    {
        return match ($slug) {
            CommunicationBlockType::SUMMARY => 'blue',
            'suggestion' => 'purple',
            'warranty' => 'amber',
            'complaint' => 'red',
            'assistance' => 'emerald',
            default => 'zinc',
        };
    }

    public function communicationTypeIcon(CustomerCommunicationLog $log): string
    {
        return match ($log->communicationType?->slug) {
            'phone' => 'phone',
            'email' => 'at-symbol',
            'text' => 'chat-bubble-left-right',
            'visit' => 'map-pin',
            default => 'question-mark-circle',
        };
    }

    public function customerDisplayName(CustomerCommunicationLog $log): string
    {
        return (string) ($log->customer_name ?: $this->customerName());
    }

    public function summaryFor(CustomerCommunicationLog $log): string
    {
        $summary = $log->blocks
            ->first(fn ($block): bool => $block->blockType?->slug === CommunicationBlockType::SUMMARY)
            ?->body;

        if (! is_string($summary) || trim($summary) === '') {
            return $log->isDraft() ? __('Draft in progress') : __('No summary');
        }

        return Str::limit(trim($summary), 180);
    }

    public function rowActionLabel(CustomerCommunicationLog $log): string
    {
        return $log->isDraft() ? __('Continue') : __('Edit');
    }

    public function selectedLog(): ?CustomerCommunicationLog
    {
        if ($this->selectedLogId === null) {
            return null;
        }

        $log = CustomerCommunicationLog::query()
            ->with(['communicationType', 'user', 'blocks.blockType'])
            ->find($this->selectedLogId);

        if (! $log instanceof CustomerCommunicationLog || ! $this->logBelongsToCurrentCustomer($log) || $log->isDraft()) {
            return null;
        }

        Gate::authorize('view', $log);

        return $log;
    }

    /**
     * @return Collection<int, CustomerCommunicationLogBlock>
     */
    public function selectedLogBlocks(CustomerCommunicationLog $log): Collection
    {
        return $log->blocks
            ->sortBy('position')
            ->values();
    }

    /**
     * @return Collection<int, Audit>
     */
    public function auditHistory(CustomerCommunicationLog $log): Collection
    {
        Gate::authorize('viewAuditHistory', $log);

        $blockIds = $log->blocks->pluck('id')->all();
        $blockMorphClass = (new CustomerCommunicationLogBlock)->getMorphClass();

        return Audit::query()
            ->with('user')
            ->where(function ($query) use ($blockIds, $blockMorphClass, $log): void {
                $query->where(function ($query) use ($log): void {
                    $query
                        ->where('auditable_type', $log->getMorphClass())
                        ->where('auditable_id', $log->getKey());
                });

                if ($blockIds !== []) {
                    $query->orWhere(function ($query) use ($blockIds, $blockMorphClass): void {
                        $query
                            ->where('auditable_type', $blockMorphClass)
                            ->whereIn('auditable_id', $blockIds);
                    });
                }
            })
            ->latest()
            ->get()
            ->filter(fn (Audit $audit): bool => $this->visibleAuditChanges($audit) !== [])
            ->values();
    }

    /**
     * @return array<string, array{old?: mixed, new?: mixed}>
     */
    public function visibleAuditChanges(Audit $audit): array
    {
        return collect($audit->getModified())
            ->only(self::VISIBLE_AUDIT_ATTRIBUTES)
            ->reject(fn (array $change): bool => $change === [])
            ->all();
    }

    public function auditEventLabel(Audit $audit): string
    {
        return match ($audit->event) {
            'created' => __('Created'),
            'updated' => __('Updated'),
            'deleted' => __('Deleted'),
            default => Str::headline((string) $audit->event),
        };
    }

    public function auditSubjectLabel(Audit $audit, CustomerCommunicationLog $log): string
    {
        if ($audit->auditable_type === $log->getMorphClass()) {
            return __('Log details');
        }

        $block = $log->blocks->firstWhere('id', $audit->auditable_id);

        return __(':type block', [
            'type' => $block?->blockType?->name ?? __('Note'),
        ]);
    }

    public function auditActorName(Audit $audit): string
    {
        return (string) ($audit->user?->name ?? __('System'));
    }

    public function auditCreatedAtLabel(Audit $audit): string
    {
        return $audit->created_at instanceof CarbonInterface
            ? $audit->created_at->copy()->timezone($this->userTimezone())->format('M j, g:i A')
            : __('N/A');
    }

    public function auditAttributeLabel(string $attribute): string
    {
        return match ($attribute) {
            'communication_type_id' => __('Communication type'),
            'communication_block_type_id' => __('Block type'),
            'contact_person_name' => __('Contact person'),
            'contact_at' => __('Contact date'),
            'requires_follow_up' => __('Follow-up flag'),
            'submitted_at' => __('Submitted date'),
            'body' => __('Note'),
            default => Str::headline($attribute),
        };
    }

    public function auditValueLabel(string $attribute, mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('Blank');
        }

        if ($attribute === 'communication_type_id') {
            return (string) (CommunicationType::query()->find($value)?->name ?? $value);
        }

        if ($attribute === 'communication_block_type_id') {
            return (string) (CommunicationBlockType::query()->find($value)?->name ?? $value);
        }

        if (in_array($attribute, ['contact_at', 'submitted_at'], true)) {
            try {
                return CarbonImmutable::parse($value)->timezone($this->userTimezone())->format('M j, g:i A');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        if ($attribute === 'requires_follow_up') {
            return filter_var($value, FILTER_VALIDATE_BOOL) ? __('Flagged') : __('Not flagged');
        }

        if ($attribute === 'status') {
            return Str::headline((string) $value);
        }

        return Str::limit((string) $value, 220);
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

    private function findLogForCurrentCustomer(string $logId): CustomerCommunicationLog
    {
        $log = CustomerCommunicationLog::query()
            ->with(['communicationType', 'user', 'blocks.blockType'])
            ->findOrFail($logId);

        abort_unless($this->logBelongsToCurrentCustomer($log), 404);

        return $log;
    }

    private function logBelongsToCurrentCustomer(CustomerCommunicationLog $log): bool
    {
        return ($this->customerId() > 0 && $log->netsuite_customer_id === $this->customerId())
            || $log->customer_account_number === $this->accountNumber;
    }

    private function userTimezone(): string
    {
        $timezone = Auth::user()?->timezone;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
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
                    container:class="max-h-[36rem] overflow-hidden w-full max-w-full"
                    table:class="min-w-0 w-full table-fixed"
                    table:style="table-layout:fixed;"
                >
                    <flux:table.columns sticky>
                        <flux:table.column>{{ __('Customer') }}</flux:table.column>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Summary') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->communicationLogs as $log)
                            <flux:table.row
                                :key="'communication-log-'.$log->id"
                                wire:click="viewLog('{{ $log->id }}')"
                                class="{{ $this->logRowClass($log) }}"
                            >
                                <flux:table.cell class="flex min-w-0 flex-row items-center gap-2 whitespace-nowrap">
                                    <flux:avatar :name="$log->user?->name ?? __('Unknown')" color="auto" circle>
                                        <x-slot:badge>
                                            <flux:icon variant="micro" :name="$this->communicationTypeIcon($log)" />
                                        </x-slot:badge>
                                    </flux:avatar>
                                    {{ $log->contact_person_name ?: __('N/A') }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    {{ $this->contactAtLabel($log) }}
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="flex flex-wrap items-center gap-2">
                                        @if ($log->requires_follow_up)
                                            <flux:badge size="sm" inset="top bottom" color="amber" icon="flag">
                                                {{ __('Follow-up') }}
                                            </flux:badge>
                                        @endif
                                        <flux:badge size="sm" inset="top bottom" color="{{ $this->statusBadgeColor($log) }}">
                                            {{ $this->statusLabel($log) }}
                                        </flux:badge>
                                        @if ($log->isDraft())
                                            @can('delete', $log)
                                                <flux:button
                                                    size="xs"
                                                    type="button"
                                                    variant="subtle"
                                                    icon="trash"
                                                    wire:click.stop="deleteDraft('{{ $log->id }}')"
                                                    wire:confirm="Are you sure you want to delete this log?"
                                                />
                                            @endcan
                                        @endif
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell class="max-w-md">
                                    <span class="block truncate whitespace-nowrap overflow-hidden">{{ $this->summaryFor($log) }}</span>
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
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <flux:heading size="md">{{ __('Purchase History') }}</flux:heading>
                        <flux:text>{{ __('Last 12 months') }}</flux:text>
                    </div>
                    @if ($this->purchaseHistory !== [])
                        <flux:badge size="sm" color="emerald">{{ $this->purchaseHistoryTotalLabel() }}</flux:badge>
                    @endif
                </div>

                @if ($this->purchaseHistory === [])
                    <div class="rounded-lg border border-zinc-200 p-4 text-sm text-zinc-600 dark:border-white/10 dark:text-zinc-300">
                        {{ __('No purchase history found yet.') }}
                    </div>
                @else
                    <div class="flex h-32 items-end gap-2 border-b border-zinc-200 pb-2 dark:border-white/10">
                        @foreach ($this->purchaseHistory as $point)
                            <div class="flex min-w-0 flex-1 flex-col items-center gap-2">
                                <div class="flex h-24 w-full items-end">
                                    <div
                                        class="w-full rounded-t bg-emerald-500/70 dark:bg-emerald-400/70"
                                        style="height: {{ $this->purchaseHistoryBarHeight($point) }}"
                                        title="{{ $point['label'] }}: {{ $this->moneyLabel($point['amount']) }}"
                                    ></div>
                                </div>
                                <div class="max-w-full truncate text-[0.65rem] text-zinc-500 dark:text-zinc-400">
                                    {{ Str::before($point['label'], ' ') }}
                                </div>
                            </div>
                        @endforeach
                    </div>

                    <div class="space-y-2">
                        @foreach (collect($this->purchaseHistory)->sortByDesc('period')->take(3) as $point)
                            <div class="flex items-center justify-between gap-3 text-sm">
                                <span class="text-zinc-600 dark:text-zinc-300">{{ $point['label'] }}</span>
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $this->moneyLabel($point['amount']) }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>

            <flux:card class="space-y-4">
                <div>
                    <flux:heading size="md">{{ __('New Product Gaps') }}</flux:heading>
                    <flux:text>{{ __('Recently released items not purchased') }}</flux:text>
                </div>

                @if ($this->newProductGaps === [])
                    <div class="rounded-lg border border-zinc-200 p-4 text-sm text-zinc-600 dark:border-white/10 dark:text-zinc-300">
                        {{ __('No new product gaps found yet.') }}
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($this->newProductGaps as $item)
                            <div class="border-b border-zinc-200 pb-3 last:border-b-0 last:pb-0 dark:border-white/10">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $item['name'] }}</div>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    @if ($item['itemid'] !== '')
                                        <flux:badge size="sm" color="zinc">{{ $item['itemid'] }}</flux:badge>
                                    @endif
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $this->releasedAtLabel($item['released_at']) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </flux:card>
        </aside>
    </div>

    <flux:modal wire:model.self="showLogDetails" class="md:w-2xl">
        @if ($showLogDetails && $selectedLog = $this->selectedLog())
            <div class="space-y-6">
                <div class="space-y-3">
                    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div class="space-y-1">
                            <flux:heading size="lg">{{ __('Communication Log') }}</flux:heading>
                            <flux:text>{{ $this->contactAtLabel($selectedLog) }}</flux:text>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            @if ($selectedLog->requires_follow_up)
                                <flux:badge size="sm" color="amber" icon="flag">{{ __('Follow-up') }}</flux:badge>
                            @endif
                            <flux:badge size="sm" color="{{ $this->communicationTypeBadgeColor($selectedLog) }}">
                                {{ $selectedLog->communicationType?->name ?? __('Unknown') }}
                            </flux:badge>
                            <flux:badge size="sm" color="{{ $this->statusBadgeColor($selectedLog) }}">
                                {{ $this->statusLabel($selectedLog) }}
                            </flux:badge>
                        </div>
                    </div>

                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Contact person') }}</dt>
                            <dd class="text-zinc-900 dark:text-zinc-100">{{ $selectedLog->contact_person_name ?: __('N/A') }}</dd>
                        </div>
                        <div>
                            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Logged by') }}</dt>
                            <dd class="text-zinc-900 dark:text-zinc-100">{{ $selectedLog->user?->name ?? __('Unknown') }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="space-y-4">
                    @foreach ($this->selectedLogBlocks($selectedLog) as $block)
                        <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                            <flux:badge size="sm" color="{{ $this->blockTypeBadgeColor($block->blockType?->slug) }}">
                                {{ $block->blockType?->name ?? __('Note') }}
                            </flux:badge>
                            <div class="whitespace-pre-wrap text-sm leading-6 text-zinc-900 dark:text-zinc-100">{{ trim((string) $block->body) !== '' ? $block->body : __('No content') }}</div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex flex-wrap gap-2">
                        <flux:button
                            type="button"
                            :variant="$this->followUpButtonVariant($selectedLog)"
                            icon="flag"
                            wire:click="toggleFollowUp('{{ $selectedLog->id }}')"
                        >
                            {{ $this->followUpButtonLabel($selectedLog) }}
                        </flux:button>

                        @can('viewAuditHistory', $selectedLog)
                            <flux:button type="button" variant="ghost" icon="clock" wire:click="viewLogHistory('{{ $selectedLog->id }}')">
                                {{ __('History') }}
                            </flux:button>
                        @endcan
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="filled">{{ __('Close') }}</flux:button>
                        </flux:modal.close>
                        <flux:button type="button" variant="primary" icon="pencil" wire:click="editLog('{{ $selectedLog->id }}')">
                            {{ __('Edit') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        @endif
    </flux:modal>

    <flux:modal wire:model.self="showLogHistory" class="md:w-3xl">
        @if ($showLogHistory && $historyLog = $this->selectedLog())
            <div class="space-y-6">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="space-y-1">
                        <flux:heading size="lg">{{ __('History & Edits') }}</flux:heading>
                        <flux:text>{{ $this->customerDisplayName($historyLog) }}</flux:text>
                    </div>

                    <flux:badge size="sm" color="{{ $this->statusBadgeColor($historyLog) }}">
                        {{ $this->statusLabel($historyLog) }}
                    </flux:badge>
                </div>

                @php($audits = $this->auditHistory($historyLog))

                @if ($audits->isEmpty())
                    <div class="rounded-lg border border-zinc-200 p-4 text-sm text-zinc-600 dark:border-white/10 dark:text-zinc-300">
                        {{ __('No visible edit history yet.') }}
                    </div>
                @else
                    <div class="space-y-4">
                        @foreach ($audits as $audit)
                            <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                                <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <flux:badge size="sm" color="{{ $audit->event === 'created' ? 'emerald' : 'blue' }}">
                                            {{ $this->auditEventLabel($audit) }}
                                        </flux:badge>
                                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $this->auditSubjectLabel($audit, $historyLog) }}
                                        </span>
                                    </div>
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __(':user · :date', ['user' => $this->auditActorName($audit), 'date' => $this->auditCreatedAtLabel($audit)]) }}
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    @foreach ($this->visibleAuditChanges($audit) as $attribute => $change)
                                        <div class="grid gap-2 text-sm sm:grid-cols-[9rem_1fr]">
                                            <div class="font-medium text-zinc-700 dark:text-zinc-200">
                                                {{ $this->auditAttributeLabel($attribute) }}
                                            </div>
                                            <div class="min-w-0 space-y-1 text-zinc-600 dark:text-zinc-300">
                                                @if (array_key_exists('old', $change))
                                                    <div>
                                                        <span class="text-zinc-400 dark:text-zinc-500">{{ __('From') }}</span>
                                                        <span class="ml-1 wrap-break-word">{{ $this->auditValueLabel($attribute, $change['old']) }}</span>
                                                    </div>
                                                @endif
                                                @if (array_key_exists('new', $change))
                                                    <div>
                                                        <span class="text-zinc-400 dark:text-zinc-500">{{ __('To') }}</span>
                                                        <span class="ml-1 wrap-break-word">{{ $this->auditValueLabel($attribute, $change['new']) }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="filled">{{ __('Close') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="button" variant="primary" icon="document-text" wire:click="viewSelectedLogDetails">
                        {{ __('View log') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</section>
