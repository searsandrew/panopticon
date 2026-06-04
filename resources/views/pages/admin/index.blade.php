<?php

use App\Models\CommunicationBlockType;
use App\Models\CommunicationType;
use App\Models\CustomerCommunicationLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    private const ADMIN_LOGS_PAGE = 'admin-logs-page';

    public string $userFilter = 'all';

    public string $blockTypeFilter = 'all';

    public string $readStateFilter = 'active';

    public function mount(): void
    {
        $this->adminUser();
    }

    /**
     * @return LengthAwarePaginator<int, CustomerCommunicationLog>
     */
    #[Computed]
    public function newCommunicationLogs(): LengthAwarePaginator
    {
        $admin = $this->adminUser();

        $query = CustomerCommunicationLog::query()
            ->with(['communicationType', 'user', 'blocks.blockType'])
            ->withExists([
                'readByUsers as read_by_current_admin_exists' => fn ($query) => $query->where('users.id', $admin->id),
            ])
            ->visibleToUsers()
            ->whereNotNull('submitted_at');

        $this->applyAdminLogFilters($query, $admin);

        return $query
            ->orderByDesc('submitted_at')
            ->orderByDesc('created_at')
            ->paginate(perPage: 12, pageName: self::ADMIN_LOGS_PAGE);
    }

    /**
     * @return EloquentCollection<int, User>
     */
    #[Computed]
    public function userFilterOptions(): EloquentCollection
    {
        return User::query()
            ->whereIn('id', CustomerCommunicationLog::query()
                ->withTrashed()
                ->visibleToUsers()
                ->select('user_id')
                ->distinct())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * @return EloquentCollection<int, CommunicationBlockType>
     */
    #[Computed]
    public function blockTypeFilterOptions(): EloquentCollection
    {
        return CommunicationBlockType::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[On('communication-log-saved')]
    public function refreshLogs(): void
    {
        $this->resetAdminLogQueue();
    }

    public function updatedUserFilter(): void
    {
        $this->resetAdminLogQueue();
    }

    public function updatedBlockTypeFilter(): void
    {
        $this->resetAdminLogQueue();
    }

    public function updatedReadStateFilter(): void
    {
        $this->resetAdminLogQueue();
    }

    public function resetFilters(): void
    {
        $this->userFilter = 'all';
        $this->blockTypeFilter = 'all';
        $this->readStateFilter = 'active';

        $this->resetAdminLogQueue();
    }

    public function openLog(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('view', $log);

        $this->markLogRead($log);
        unset($this->newCommunicationLogs);

        $this->dispatch('open-communication-log-detail', logId: $log->id);
    }

    public function markAsRead(string $logId): void
    {
        $this->markLogRead($this->findVisibleLog($logId));

        Flux::toast(variant: 'success', text: __('Log marked read.'));

        unset($this->newCommunicationLogs);
    }

    public function clearLog(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('view', $log);

        $this->setAdminLogState($log, readAt: now(), clearedAt: now());

        Flux::toast(variant: 'success', text: __('Log cleared.'));

        $this->dispatch('close-communication-log-detail');
        unset($this->newCommunicationLogs);
    }

    public function markAsUnread(string $logId): void
    {
        $log = $this->findVisibleLog($logId);
        $admin = $this->adminUser();

        Gate::authorize('view', $log);

        $log->readByUsers()->detach($admin->id);

        Flux::toast(variant: 'success', text: __('Log marked unread.'));

        unset($this->newCommunicationLogs);
    }

    public function toggleRead(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        if ($this->logIsReadByAdmin($log)) {
            $this->markAsUnread($log->id);

            return;
        }

        $this->markAsRead($log->id);
    }

    public function flagForFollowUp(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('update', $log);

        $log->forceFill([
            'requires_follow_up' => true,
        ])->save();

        Flux::toast(variant: 'success', text: __('Follow-up flagged.'));

        $this->dispatch('communication-log-saved');
    }

    public function requestUpdate(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('update', $log);

        $log->forceFill([
            'status' => CustomerCommunicationLog::STATUS_UPDATE_REQUESTED,
        ])->save();

        Flux::toast(variant: 'success', text: __('Update requested.'));

        $this->dispatch('communication-log-saved');
    }

    public function archiveLog(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('delete', $log);

        $log->delete();

        Flux::toast(variant: 'success', text: __('Log archived.'));

        $this->dispatch('close-communication-log-detail');
        $this->dispatch('communication-log-saved');
    }

    public function newSinceLabel(): string
    {
        return __('Uncleared for you');
    }

    public function submittedAtLabel(CustomerCommunicationLog $log): string
    {
        return $log->submitted_at instanceof CarbonInterface
            ? $log->submitted_at->copy()->timezone($this->userTimezone())->format('M j, g:i A')
            : __('N/A');
    }

    public function communicationAtLabel(CustomerCommunicationLog $log): string
    {
        $communicatedAt = $log->contact_at instanceof CarbonInterface
            ? $log->contact_at
            : $log->submitted_at;

        return $communicatedAt instanceof CarbonInterface
            ? $communicatedAt->copy()->timezone($this->userTimezone())->format('M j, g:i A')
            : __('N/A');
    }

    public function customerLabel(CustomerCommunicationLog $log): string
    {
        return $log->customer_name ?: $log->customer_account_number;
    }

    public function loggedByLabel(CustomerCommunicationLog $log): string
    {
        return $log->user?->name ?? __('Unknown');
    }

    public function communicationTypeBadgeColor(CustomerCommunicationLog $log): string
    {
        return match ($log->communicationType?->slug) {
            CommunicationType::PHONE => 'green',
            'email' => 'blue',
            'text' => 'sky',
            'visit' => 'amber',
            default => 'zinc',
        };
    }

    public function communicationTypeIcon(CustomerCommunicationLog $log): string
    {
        return match ($log->communicationType?->slug) {
            CommunicationType::PHONE => 'phone',
            'email' => 'at-symbol',
            'text' => 'chat-bubble-left-right',
            'visit' => 'map-pin',
            default => 'question-mark-circle',
        };
    }

    public function communicationTypeLabel(CustomerCommunicationLog $log): string
    {
        return $log->communicationType?->name ?? __('Unknown');
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

    public function statusBadgeColor(CustomerCommunicationLog $log): string
    {
        return $log->isUpdateRequested() ? 'red' : 'emerald';
    }

    public function statusLabel(CustomerCommunicationLog $log): string
    {
        return $log->isUpdateRequested() ? __('Update requested') : __('Submitted');
    }

    public function readActionLabel(CustomerCommunicationLog $log): string
    {
        return $this->logIsRead($log) ? __('Mark as Unread') : __('Mark as Read');
    }

    public function logIsRead(CustomerCommunicationLog $log): bool
    {
        return (bool) ($log->read_by_current_admin_exists ?? false);
    }

    public function logRowClass(CustomerCommunicationLog $log): string
    {
        $classes = 'group cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5';

        if ($log->requires_follow_up) {
            $classes .= ' bg-amber-50/60 hover:bg-amber-100/70 dark:bg-amber-500/10 dark:hover:bg-amber-500/15';
        }

        return $classes;
    }

    public function readStateFilterLabel(): string
    {
        return match ($this->readStateFilter) {
            'unread' => __('Unread'),
            'read' => __('Read'),
            'archived' => __('Archived'),
            default => __('All active'),
        };
    }

    public function hasActiveFilters(): bool
    {
        return $this->userFilter !== 'all'
            || $this->blockTypeFilter !== 'all'
            || $this->readStateFilter !== 'active';
    }

    private function markLogRead(CustomerCommunicationLog $log): void
    {
        Gate::authorize('view', $log);

        if ($this->logIsReadByAdmin($log)) {
            $this->setAdminLogState($log, readAt: now(), clearedAt: null);

            return;
        }

        $this->setAdminLogState($log, readAt: now(), clearedAt: null);
    }

    private function logIsReadByAdmin(CustomerCommunicationLog $log): bool
    {
        return $log->readByUsers()
            ->whereKey($this->adminUser()->id)
            ->exists();
    }

    private function setAdminLogState(CustomerCommunicationLog $log, CarbonInterface $readAt, ?CarbonInterface $clearedAt): void
    {
        $admin = $this->adminUser();
        $attributes = [
            'cleared_at' => $clearedAt,
            'read_at' => $readAt,
        ];

        if ($log->readByUsers()->whereKey($admin->id)->exists()) {
            $log->readByUsers()->updateExistingPivot($admin->id, $attributes);

            return;
        }

        $log->readByUsers()->attach($admin->id, $attributes);
    }

    /**
     * @param  Builder<CustomerCommunicationLog>  $query
     */
    private function applyAdminLogFilters(Builder $query, User $admin): void
    {
        if ($this->readStateFilter === 'archived') {
            $query->onlyTrashed();
        } else {
            $this->excludeClearedLogs($query, $admin);
        }

        if ($this->readStateFilter === 'unread') {
            $query->whereDoesntHave('readByUsers', fn (Builder $query): Builder => $query->where('users.id', $admin->id));
        }

        if ($this->readStateFilter === 'read') {
            $query->whereHas('readByUsers', fn (Builder $query): Builder => $query->where('users.id', $admin->id));
        }

        if ($this->userFilter !== 'all') {
            $query->where('user_id', $this->userFilter);
        }

        if ($this->blockTypeFilter !== 'all') {
            $query->whereHas('blocks', fn (Builder $query): Builder => $query->where('communication_block_type_id', $this->blockTypeFilter));
        }
    }

    /**
     * @param  Builder<CustomerCommunicationLog>  $query
     */
    private function excludeClearedLogs(Builder $query, User $admin): void
    {
        $query->whereDoesntHave('readByUsers', function (Builder $query) use ($admin): void {
            $query
                ->where('users.id', $admin->id)
                ->whereNotNull('customer_communication_log_reads.cleared_at');
        });
    }

    private function findVisibleLog(string $logId): CustomerCommunicationLog
    {
        return CustomerCommunicationLog::query()
            ->withTrashed()
            ->visibleToUsers()
            ->findOrFail($logId);
    }

    private function resetAdminLogQueue(): void
    {
        unset($this->newCommunicationLogs);

        $this->resetPage(self::ADMIN_LOGS_PAGE);
    }

    private function adminUser(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $user;
    }

    private function userTimezone(): string
    {
        $timezone = $this->adminUser()->timezone;

        return is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : (string) config('app.timezone', 'UTC');
    }
};
?>

<section class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-col gap-1">
        <flux:heading size="xl">{{ __('Admin') }}</flux:heading>
        <flux:text>{{ $this->newSinceLabel() }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <section class="space-y-4 lg:col-span-2">
            <div class="flex flex-col gap-1">
                <flux:heading size="lg">{{ __('Log Queue') }}</flux:heading>
                <flux:text>{{ trans_choice(':count log|:count logs', $this->newCommunicationLogs->total(), ['count' => $this->newCommunicationLogs->total()]) }}</flux:text>
            </div>

            @if ($this->newCommunicationLogs->total() === 0)
                <div class="rounded-lg border border-neutral-200 p-6 text-sm text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                    {{ __('No uncleared communication logs.') }}
                </div>
            @else
                <flux:table
                    id="admin-new-logs"
                    :paginate="$this->newCommunicationLogs"
                    pagination:scroll-to="#admin-new-logs"
                    container:class="max-h-[36rem] w-full max-w-full"
                    class="min-w-[54rem] w-full md:min-w-full"
                    style="table-layout: fixed;"
                >
                    <flux:table.columns sticky>
                        <flux:table.column class="w-[27%]">{{ __('Customer') }}</flux:table.column>
                        <flux:table.column class="w-[18%]">{{ __('Communication Time') }}</flux:table.column>
                        <flux:table.column class="w-[17%]">{{ __('Contacted by') }}</flux:table.column>
                        <flux:table.column class="w-[26%]">{{ __('Type') }}</flux:table.column>
                        <flux:table.column class="w-[12%]" align="end">{{ __('Status') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->newCommunicationLogs as $log)
                            <flux:table.row
                                :key="'admin-new-log-'.$log->id"
                                wire:click="openLog('{{ $log->id }}')"
                                x-data
                                x-on:contextmenu.prevent.stop="$refs.contextTrigger.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: $event.clientX, clientY: $event.clientY }))"
                                class="{{ $this->logRowClass($log) }}"
                            >
                                <flux:table.cell>
                                    <span class="flex min-w-0 items-start gap-2">
                                        @if (! $this->logIsRead($log))
                                            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-blue-500 ring-2 ring-blue-100 dark:ring-blue-400/20">
                                                <span class="sr-only">{{ __('Unread') }}</span>
                                            </span>
                                        @else
                                            <span class="mt-1.5 size-2 shrink-0"></span>
                                        @endif
                                        <span class="flex min-w-0 flex-col gap-1">
                                            <span class="truncate font-medium text-zinc-900 dark:text-zinc-100">{{ $this->customerLabel($log) }}</span>
                                            <span class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $log->customer_account_number }}</span>
                                        </span>
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-nowrap">
                                    <span class="text-zinc-700 dark:text-zinc-200">{{ $this->communicationAtLabel($log) }}</span>
                                </flux:table.cell>
                                <flux:table.cell>
                                    <span class="block min-w-0 truncate text-zinc-700 dark:text-zinc-200">{{ $this->loggedByLabel($log) }}</span>
                                </flux:table.cell>
                                <flux:table.cell class="whitespace-normal">
                                    <span class="flex min-w-0 flex-wrap items-center gap-1.5">
                                        <flux:badge size="sm" inset="top bottom" color="{{ $this->communicationTypeBadgeColor($log) }}" icon="{{ $this->communicationTypeIcon($log) }}">
                                            {{ $this->communicationTypeLabel($log) }}
                                        </flux:badge>
                                        @foreach ($log->blocks->pluck('blockType')->filter()->unique('id')->sortBy('sort_order') as $blockType)
                                            <flux:badge size="sm" inset="top bottom" color="{{ $this->blockTypeBadgeColor($blockType->slug) }}">
                                                {{ $blockType->name }}
                                            </flux:badge>
                                        @endforeach
                                        @if ($log->requires_follow_up)
                                            <flux:badge size="sm" inset="top bottom" color="amber" icon="flag">{{ __('Follow-up') }}</flux:badge>
                                        @endif
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="end" wire:click.stop>
                                    <div class="flex items-center justify-end">
                                        <flux:context position="bottom end">
                                            <span x-ref="contextTrigger" class="sr-only">{{ __('Open log actions') }}</span>

                                            <flux:menu>
                                                <flux:menu.item icon="eye" wire:click.stop="openLog('{{ $log->id }}')">
                                                    {{ __('Open') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="{{ $this->logIsRead($log) ? 'envelope' : 'envelope-open' }}" wire:click.stop="toggleRead('{{ $log->id }}')">
                                                    {{ $this->readActionLabel($log) }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="check" wire:click.stop="clearLog('{{ $log->id }}')">
                                                    {{ __('Clear') }}
                                                </flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item icon="flag" wire:click.stop="flagForFollowUp('{{ $log->id }}')">
                                                    {{ __('Flag for Followup') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="exclamation-circle" wire:click.stop="requestUpdate('{{ $log->id }}')">
                                                    {{ __('Request Update') }}
                                                </flux:menu.item>
                                                <flux:menu.separator />
                                                @if ($log->trashed())
                                                    <flux:menu.item icon="archive-box" disabled>
                                                        {{ __('Archived') }}
                                                    </flux:menu.item>
                                                @else
                                                    <flux:menu.item icon="archive-box" variant="danger" wire:click.stop="archiveLog('{{ $log->id }}')">
                                                        {{ __('Archive') }}
                                                    </flux:menu.item>
                                                @endif
                                            </flux:menu>
                                        </flux:context>

                                        <flux:badge size="sm" inset="top bottom" color="{{ $this->statusBadgeColor($log) }}">
                                            {{ $this->statusLabel($log) }}
                                        </flux:badge>
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            @endif
        </section>

        <aside class="space-y-4">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Filters') }}</flux:heading>
                <flux:text>{{ $this->readStateFilterLabel() }}</flux:text>
            </div>

            <div class="space-y-4">
                <flux:select wire:model.live="userFilter" :label="__('User')">
                    <flux:select.option value="all">{{ __('All users') }}</flux:select.option>
                    @foreach ($this->userFilterOptions as $user)
                        <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="blockTypeFilter" :label="__('Block category')">
                    <flux:select.option value="all">{{ __('All categories') }}</flux:select.option>
                    @foreach ($this->blockTypeFilterOptions as $blockType)
                        <flux:select.option value="{{ $blockType->id }}">{{ $blockType->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="readStateFilter" :label="__('Status')">
                    <flux:select.option value="active">{{ __('All active') }}</flux:select.option>
                    <flux:select.option value="unread">{{ __('Unread') }}</flux:select.option>
                    <flux:select.option value="read">{{ __('Read') }}</flux:select.option>
                    <flux:select.option value="archived">{{ __('Archived') }}</flux:select.option>
                </flux:select>

                @if ($this->hasActiveFilters())
                    <flux:button type="button" variant="ghost" icon="x-mark" wire:click="resetFilters">
                        {{ __('Reset filters') }}
                    </flux:button>
                @endif
            </div>
        </aside>
    </div>

    <livewire:customer-communication-log-detail-modal />
    <livewire:customer-communication-log-flyout
        :customer="[]"
        account-number=""
        trigger-label=""
        trigger-icon=""
        trigger-variant=""
        :show-trigger="false"
        :open-any-customer-log="true"
        :key="'admin-communication-log-editor'"
    />
</section>
