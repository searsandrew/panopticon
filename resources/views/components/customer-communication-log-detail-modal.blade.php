<?php

use App\Models\CustomerCommunicationLog;
use App\Models\CustomerCommunicationLogBlock;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public bool $showLogDetails = false;

    public ?string $selectedLogId = null;

    #[On('open-communication-log-detail')]
    public function open(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('view', $log);

        $this->selectedLogId = $log->id;
        $this->showLogDetails = true;
    }

    public function viewLog(string $logId): void
    {
        $this->open($logId);
    }

    #[On('close-communication-log-detail')]
    public function close(): void
    {
        $this->showLogDetails = false;
        $this->selectedLogId = null;
    }

    public function updatedShowLogDetails(bool $value): void
    {
        if (! $value) {
            $this->selectedLogId = null;
        }
    }

    public function toggleFollowUp(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('update', $log);

        $log->forceFill([
            'requires_follow_up' => ! $log->requires_follow_up,
        ])->save();

        $this->selectedLogId = $log->id;

        Flux::toast(variant: 'success', text: $log->requires_follow_up ? __('Follow-up flagged.') : __('Follow-up cleared.'));

        $this->dispatch('communication-log-saved');
    }

    public function editLog(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('update', $log);

        $this->close();

        $this->dispatch('open-communication-log-editor', logId: $log->id);
    }

    public function provideUpdate(string $logId): void
    {
        $log = $this->findVisibleLog($logId);

        Gate::authorize('view', $log);
        Gate::authorize('create', CustomerCommunicationLog::class);

        $this->close();

        $this->dispatch('provide-communication-log-update', logId: $log->id);
    }

    public function selectedLog(): ?CustomerCommunicationLog
    {
        if ($this->selectedLogId === null) {
            return null;
        }

        $log = CustomerCommunicationLog::query()
            ->withTrashed()
            ->with(['communicationType', 'updateRequest.updateRequester', 'updateResponses', 'user', 'blocks.blockType'])
            ->visibleToUsers()
            ->find($this->selectedLogId);

        if (! $log instanceof CustomerCommunicationLog) {
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

    private function findVisibleLog(string $logId): CustomerCommunicationLog
    {
        return CustomerCommunicationLog::query()
            ->withTrashed()
            ->with(['communicationType', 'updateRequest.updateRequester', 'updateResponses', 'user', 'blocks.blockType'])
            ->visibleToUsers()
            ->findOrFail($logId);
    }
};
?>

<flux:modal wire:model.self="showLogDetails" class="md:w-2xl">
    @if ($selectedLog = $this->selectedLog())
        <x-customer-communication-log-detail :log="$selectedLog" :blocks="$this->selectedLogBlocks($selectedLog)">
            <x-slot:actions>
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <flux:button
                        type="button"
                        :variant="$selectedLog->requires_follow_up ? 'filled' : 'ghost'"
                        icon="flag"
                        wire:click="toggleFollowUp('{{ $selectedLog->id }}')"
                    >
                        {{ $selectedLog->requires_follow_up ? __('Follow-up flagged') : __('Flag follow-up') }}
                    </flux:button>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button type="button" variant="filled">{{ __('Close') }}</flux:button>
                        </flux:modal.close>
                        @if (! $selectedLog->trashed())
                            @if ($selectedLog->isUpdateRequested())
                                <flux:button type="button" variant="primary" icon="chat-bubble-left-right" wire:click="provideUpdate('{{ $selectedLog->id }}')">
                                    {{ __('Provide Update') }}
                                </flux:button>
                            @endif
                            <flux:button type="button" variant="{{ $selectedLog->isUpdateRequested() ? 'ghost' : 'primary' }}" icon="pencil" wire:click="editLog('{{ $selectedLog->id }}')">
                                {{ __('Edit') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            </x-slot:actions>
        </x-customer-communication-log-detail>
    @endif
</flux:modal>
