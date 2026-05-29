<?php

use App\Models\CommunicationBlockType;
use App\Models\CustomerCommunicationLog;
use App\Models\CustomerCommunicationLogBlock;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    /**
     * @var array<string, mixed>
     */
    public array $customer = [];

    public string $accountNumber = '';

    public string $triggerLabel = 'Log';

    public ?string $triggerIcon = 'chat-bubble-left-ellipsis';

    public string $triggerSize = 'sm';

    public string $triggerVariant = '';

    public bool $showLogList = false;

    public bool $showLogDetails = false;

    public ?string $selectedLogId = null;

    /**
     * @param  array<string, mixed>  $customer
     */
    public function mount(
        array $customer,
        string $accountNumber,
        string $triggerLabel = 'Log',
        ?string $triggerIcon = 'chat-bubble-left-ellipsis',
        string $triggerSize = 'sm',
        string $triggerVariant = '',
    ): void {
        $this->customer = $customer;
        $this->accountNumber = (string) (data_get($customer, 'account_number') ?: $accountNumber);
        $this->triggerLabel = $triggerLabel;
        $this->triggerIcon = $triggerIcon;
        $this->triggerSize = $triggerSize;
        $this->triggerVariant = $triggerVariant;
    }

    public function openList(): void
    {
        Gate::authorize('viewAny', CustomerCommunicationLog::class);

        abort_unless($this->userCanAccessCustomer(), 403);

        unset($this->communicationLogs);

        $this->showLogList = true;
    }

    public function viewLog(string $logId): void
    {
        $log = $this->findLogForCurrentCustomer($logId);

        if ($log->isDraft()) {
            Gate::authorize('update', $log);

            $this->selectedLogId = null;
            $this->showLogDetails = false;
            $this->showLogList = false;

            $this->dispatch('open-communication-log-editor', logId: $log->id);

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
        $this->showLogList = false;

        $this->dispatch('open-communication-log-editor', logId: $log->id);
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
        }

        $this->dispatch('communication-log-saved');
    }

    public function updatedShowLogDetails(bool $value): void
    {
        if (! $value) {
            $this->selectedLogId = null;
        }
    }

    /**
     * @return Collection<int, CustomerCommunicationLog>
     */
    #[Computed]
    public function communicationLogs(): Collection
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
            ->limit(20)
            ->get();
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

    public function customerName(): string
    {
        return (string) (data_get($this->customer, 'companyname') ?: data_get($this->customer, 'entityid') ?: $this->accountNumber);
    }

    public function contactAtLabel(CustomerCommunicationLog $log): string
    {
        return $log->contact_at instanceof CarbonInterface
            ? $log->contact_at->copy()->timezone($this->userTimezone())->format('M j, g:i A')
            : __('N/A');
    }

    public function summaryFor(CustomerCommunicationLog $log): string
    {
        $summary = $log->blocks
            ->first(fn ($block): bool => $block->blockType?->slug === CommunicationBlockType::SUMMARY)
            ?->body;

        if (! is_string($summary) || trim($summary) === '') {
            return $log->isDraft() ? __('Draft in progress') : __('No summary');
        }

        return Str::limit(trim($summary), 140);
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

    public function statusBadgeColor(CustomerCommunicationLog $log): string
    {
        return $log->isDraft() ? 'zinc' : 'emerald';
    }

    public function statusLabel(CustomerCommunicationLog $log): string
    {
        return $log->isDraft() ? __('Draft') : __('Submitted');
    }

    public function rowClass(CustomerCommunicationLog $log): string
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

    private function userCanAccessCustomer(): bool
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return false;
        }

        return collect(['sales_rep_id', 'pipeline_owner_id'])
            ->contains(fn (string $key): bool => data_get($this->customer, $key) !== null
                && $user->canAccessNetSuiteSalesRep(data_get($this->customer, $key)));
    }

    private function findLogForCurrentCustomer(string $logId): CustomerCommunicationLog
    {
        $log = CustomerCommunicationLog::query()
            ->with(['communicationType', 'user', 'blocks.blockType'])
            ->findOrFail($logId);

        abort_unless($this->logBelongsToCurrentCustomer($log), 404);

        return $log;
    }

    private function customerId(): int
    {
        return (int) data_get($this->customer, 'customer_id');
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

<div>
    @can('viewAny', \App\Models\CustomerCommunicationLog::class)
        <flux:button
            type="button"
            :size="$triggerSize"
            :variant="$triggerVariant ?: null"
            :icon="$triggerIcon ?: null"
            wire:click="openList"
        >
            {{ __($triggerLabel) }}
        </flux:button>
    @endcan

    <flux:modal wire:model.self="showLogList" class="md:w-3xl">
        <div class="space-y-5">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Communication Logs') }}</flux:heading>
                <flux:text>{{ $this->customerName() }}</flux:text>
            </div>

            @if ($this->communicationLogs->isEmpty())
                <div class="rounded-lg border border-zinc-200 p-5 text-sm text-zinc-600 dark:border-white/10 dark:text-zinc-300">
                    {{ __('No communication logs yet.') }}
                </div>
            @else
                <flux:table table:class="min-w-0 w-full table-fixed" table:style="table-layout:fixed;">
                    <flux:table.columns sticky>
                        <flux:table.column>{{ __('Date') }}</flux:table.column>
                        <flux:table.column>{{ __('Status') }}</flux:table.column>
                        <flux:table.column>{{ __('Summary') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->communicationLogs as $log)
                            <flux:table.row
                                :key="'communication-log-list-'.$log->id"
                                wire:click="viewLog('{{ $log->id }}')"
                                class="{{ $this->rowClass($log) }}"
                            >
                                <flux:table.cell class="whitespace-nowrap">
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
                                                    variant="danger"
                                                    icon="trash"
                                                    wire:click.stop="deleteDraft('{{ $log->id }}')"
                                                    wire:confirm="Are you sure you want to delete this log?"
                                                >
                                                    {{ __('Delete') }}
                                                </flux:button>
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

            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button type="button" variant="filled">{{ __('Close') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <flux:modal wire:model.self="showLogDetails" class="md:w-2xl">
        @if ($selectedLog = $this->selectedLog())
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

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button type="button" variant="filled">{{ __('Close') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="button" variant="primary" icon="pencil" wire:click="editLog('{{ $selectedLog->id }}')">
                        {{ __('Edit') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
