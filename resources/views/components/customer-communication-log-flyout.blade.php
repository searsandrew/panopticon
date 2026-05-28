<?php

use App\Models\CommunicationBlockType;
use App\Models\CommunicationType;
use App\Models\CustomerCommunicationLog;
use App\Models\CustomerCommunicationLogBlock;
use App\Models\CustomerContact;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    /**
     * @var array<string, mixed>
     */
    public array $customer = [];

    public string $accountNumber = '';

    public string $triggerLabel = 'Add new log';

    public string $triggerIcon = 'plus';

    public string $triggerSize = 'sm';

    public string $triggerVariant = 'primary';

    public bool $splitTrigger = false;

    public bool $showLogFlyout = false;

    public ?string $logId = null;

    public bool $editingSubmittedLog = false;

    public string $contactAt = '';

    public ?string $communicationTypeId = null;

    public string $contactPersonName = '';

    /**
     * @var array<int, array{id: string|null, communication_block_type_id: string|null, body: string}>
     */
    public array $blocks = [];

    public ?string $lastAutosavedAt = null;

    /**
     * @param  array<string, mixed>  $customer
     */
    public function mount(
        array $customer,
        string $accountNumber,
        string $triggerLabel = 'Add new log',
        string $triggerIcon = 'plus',
        string $triggerSize = 'sm',
        string $triggerVariant = 'primary',
        bool $splitTrigger = false,
    ): void {
        $this->customer = $customer;
        $this->accountNumber = (string) (data_get($customer, 'account_number') ?: $accountNumber);
        $this->triggerLabel = $triggerLabel;
        $this->triggerIcon = $triggerIcon;
        $this->triggerSize = $triggerSize;
        $this->triggerVariant = $triggerVariant;
        $this->splitTrigger = $splitTrigger;
    }

    public function open(): void
    {
        Gate::authorize('create', CustomerCommunicationLog::class);

        abort_unless($this->userCanAccessCustomer(), 403);

        $this->loadOrCreateDraft();
        $this->showLogFlyout = true;
    }

    #[On('open-communication-log-editor')]
    public function openExisting(string $logId): void
    {
        $log = CustomerCommunicationLog::query()
            ->with(['blocks.blockType'])
            ->findOrFail($logId);

        if (! $this->logBelongsToCurrentCustomer($log)) {
            return;
        }

        Gate::authorize('update', $log);

        $this->fillFromLog($log);
        $this->showLogFlyout = true;
    }

    public function close(): void
    {
        $this->showLogFlyout = false;
        $this->closeLogFlyout();
    }

    public function updatedShowLogFlyout(bool $value): void
    {
        if ($value) {
            return;
        }

        $this->closeLogFlyout();
    }

    public function updated(string $property): void
    {
        if (! $this->showLogFlyout) {
            return;
        }

        if (
            in_array($property, ['contactAt', 'communicationTypeId', 'contactPersonName'], true)
            || str_starts_with($property, 'blocks.')
        ) {
            $this->autosaveLog();
        }
    }

    public function addBlock(): void
    {
        Gate::authorize('update', $this->currentLog());

        $type = $this->defaultAdditionalBlockType();

        $this->blocks[] = [
            'id' => null,
            'communication_block_type_id' => $type?->id,
            'body' => '',
        ];

        $this->autosaveLog();
    }

    public function removeBlock(int $index): void
    {
        Gate::authorize('update', $this->currentLog());

        if (! array_key_exists($index, $this->blocks) || $this->isSummaryBlock($this->blocks[$index])) {
            return;
        }

        unset($this->blocks[$index]);

        $this->blocks = array_values($this->blocks);

        $this->autosaveLog();
    }

    public function selectContactSuggestion(string $name): void
    {
        $this->contactPersonName = $name;

        $this->autosaveLog();
    }

    public function submit(): void
    {
        $log = $this->currentLog();

        Gate::authorize('update', $log);

        $wasDraft = $log->isDraft();

        $this->ensureSummaryBlock();
        $this->validateLog();
        $contact = $this->rememberContact();

        $log->fill([
            'communication_type_id' => $this->communicationTypeId,
            'customer_contact_id' => $contact?->id,
            'contact_person_name' => $this->normalizedContactPersonName(),
            'contact_at' => Carbon::parse($this->contactAt),
            'status' => CustomerCommunicationLog::STATUS_SUBMITTED,
            'submitted_at' => $log->submitted_at ?? now(),
            'last_autosaved_at' => now(),
        ])->save();

        $this->syncBlocks($log);
        $this->resetLogState();

        $this->showLogFlyout = false;

        Flux::toast(variant: 'success', text: $wasDraft ? __('Communication logged.') : __('Communication log updated.'));

        $this->dispatch('communication-log-saved');
    }

    /**
     * @return Collection<int, CommunicationType>
     */
    #[Computed]
    public function communicationTypes(): Collection
    {
        return CommunicationType::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, CommunicationBlockType>
     */
    #[Computed]
    public function blockTypes(): Collection
    {
        return CommunicationBlockType::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function contactSuggestions(): array
    {
        return CustomerContact::query()
            ->where('netsuite_customer_id', $this->customerId())
            ->orderByDesc('last_used_at')
            ->orderBy('name')
            ->limit(8)
            ->pluck('name')
            ->all();
    }

    public function customerName(): string
    {
        return (string) (data_get($this->customer, 'companyname') ?: data_get($this->customer, 'entityid') ?: $this->accountNumber);
    }

    /**
     * @param  array{id: string|null, communication_block_type_id: string|null, body: string}  $block
     */
    public function isSummaryBlock(array $block): bool
    {
        return $block['communication_block_type_id'] === $this->summaryBlockType()->id;
    }

    public function blockTypeName(?string $blockTypeId): string
    {
        return (string) ($this->blockTypes->firstWhere('id', $blockTypeId)?->name ?? __('Note'));
    }

    public function flyoutHeading(): string
    {
        return $this->editingSubmittedLog ? __('Edit Communication') : __('Log Communication');
    }

    public function savedMessage(): string
    {
        return $this->editingSubmittedLog
            ? __('Saved :time', ['time' => $this->lastAutosavedAt])
            : __('Draft saved :time', ['time' => $this->lastAutosavedAt]);
    }

    public function submitButtonLabel(): string
    {
        return $this->editingSubmittedLog ? __('Save changes') : __('Submit log');
    }

    private function userCanAccessCustomer(): bool
    {
        $netsuiteUserId = Auth::user()?->netsuite_user_id;

        if ($netsuiteUserId === null) {
            return false;
        }

        return collect(['sales_rep_id', 'pipeline_owner_id'])
            ->contains(fn (string $key): bool => data_get($this->customer, $key) !== null
                && (int) data_get($this->customer, $key) === (int) $netsuiteUserId);
    }

    private function loadOrCreateDraft(): void
    {
        $log = CustomerCommunicationLog::query()
            ->with(['blocks.blockType'])
            ->where('user_id', Auth::id())
            ->where('customer_account_number', $this->accountNumber)
            ->where('status', CustomerCommunicationLog::STATUS_DRAFT)
            ->latest('updated_at')
            ->first();

        if (! $log) {
            $log = $this->createDraft();
        }

        Gate::authorize('update', $log);

        $this->fillFromLog($log->load(['blocks.blockType']));
    }

    private function createDraft(): CustomerCommunicationLog
    {
        $log = CustomerCommunicationLog::query()->create([
            'user_id' => Auth::id(),
            'netsuite_customer_id' => $this->customerId(),
            'customer_account_number' => $this->accountNumber,
            'customer_name' => $this->customerName(),
            'netsuite_sales_rep_id' => Auth::user()?->netsuite_user_id,
            'communication_type_id' => $this->defaultCommunicationType()->id,
            'contact_at' => now(),
            'status' => CustomerCommunicationLog::STATUS_DRAFT,
            'last_autosaved_at' => now(),
        ]);

        $log->blocks()->create([
            'communication_block_type_id' => $this->summaryBlockType()->id,
            'position' => 0,
            'body' => '',
        ]);

        return $log;
    }

    private function fillFromLog(CustomerCommunicationLog $log): void
    {
        $this->logId = $log->id;
        $this->editingSubmittedLog = ! $log->isDraft();
        $this->contactAt = ($log->contact_at ?? now())->format('Y-m-d\TH:i');
        $this->communicationTypeId = $log->communication_type_id;
        $this->contactPersonName = (string) $log->contact_person_name;
        $this->lastAutosavedAt = $log->last_autosaved_at?->format('g:i A');
        $this->blocks = $log->blocks
            ->sortBy('position')
            ->values()
            ->map(fn (CustomerCommunicationLogBlock $block): array => [
                'id' => $block->id,
                'communication_block_type_id' => $block->communication_block_type_id,
                'body' => (string) $block->body,
            ])
            ->all();

        $this->ensureSummaryBlock();
    }

    private function autosaveLog(): void
    {
        if ($this->logId === null) {
            return;
        }

        $log = $this->currentLog();

        Gate::authorize('update', $log);

        $attributes = [
            'communication_type_id' => $this->communicationTypeId ?: $this->defaultCommunicationType()->id,
            'contact_person_name' => $this->normalizedContactPersonName(),
            'last_autosaved_at' => now(),
        ];

        if ($contactAt = $this->parsedContactAt()) {
            $attributes['contact_at'] = $contactAt;
        }

        $log->fill($attributes)->save();
        $this->syncBlocks($log);

        $this->lastAutosavedAt = now()->format('g:i A');
    }

    private function currentLog(): CustomerCommunicationLog
    {
        return CustomerCommunicationLog::query()
            ->findOrFail($this->logId);
    }

    private function syncBlocks(CustomerCommunicationLog $log): void
    {
        $this->ensureSummaryBlock();

        $keptBlockIds = [];

        foreach (array_values($this->blocks) as $position => $block) {
            $blockTypeId = $block['communication_block_type_id'];

            if ($blockTypeId === null) {
                continue;
            }

            $record = null;

            if ($block['id'] !== null) {
                $record = $log->blocks()->find($block['id']);
            }

            $record ??= new CustomerCommunicationLogBlock([
                'customer_communication_log_id' => $log->id,
            ]);

            $record->fill([
                'communication_block_type_id' => $blockTypeId,
                'position' => $position,
                'body' => $block['body'],
            ])->save();

            $this->blocks[$position]['id'] = $record->id;
            $keptBlockIds[] = $record->id;
        }

        $log->blocks()
            ->when($keptBlockIds !== [], fn ($query) => $query->whereNotIn('id', $keptBlockIds))
            ->delete();
    }

    private function validateLog(): void
    {
        $this->validate([
            'contactAt' => ['required', 'date'],
            'communicationTypeId' => ['required', Rule::exists('communication_types', 'id')->where('is_active', true)],
            'contactPersonName' => ['nullable', 'string', 'max:255'],
            'blocks' => ['required', 'array', 'min:1'],
            'blocks.*.communication_block_type_id' => ['required', Rule::exists('communication_block_types', 'id')->where('is_active', true)],
            'blocks.*.body' => ['nullable', 'string', 'max:10000'],
        ]);

        $summaryIndex = $this->summaryBlockIndex();

        if ($summaryIndex === null || trim((string) ($this->blocks[$summaryIndex]['body'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'blocks.'.($summaryIndex ?? 0).'.body' => __('A summary is required.'),
            ]);
        }
    }

    private function rememberContact(): ?CustomerContact
    {
        $name = $this->normalizedContactPersonName();

        if ($name === null) {
            return null;
        }

        $contact = CustomerContact::query()
            ->withTrashed()
            ->firstOrNew([
                'netsuite_customer_id' => $this->customerId(),
                'normalized_name' => CustomerContact::normalizeName($name),
            ]);

        $contact->fill([
            'customer_account_number' => $this->accountNumber,
            'name' => $name,
            'last_used_at' => now(),
        ])->save();

        if ($contact->trashed()) {
            $contact->restore();
        }

        return $contact;
    }

    private function ensureSummaryBlock(): void
    {
        if ($this->summaryBlockIndex() !== null) {
            return;
        }

        array_unshift($this->blocks, [
            'id' => null,
            'communication_block_type_id' => $this->summaryBlockType()->id,
            'body' => '',
        ]);
    }

    private function summaryBlockIndex(): ?int
    {
        $summaryTypeId = $this->summaryBlockType()->id;

        foreach ($this->blocks as $index => $block) {
            if (($block['communication_block_type_id'] ?? null) === $summaryTypeId) {
                return $index;
            }
        }

        return null;
    }

    private function defaultCommunicationType(): CommunicationType
    {
        $type = CommunicationType::query()
            ->active()
            ->where('slug', CommunicationType::PHONE)
            ->first()
            ?? CommunicationType::query()->active()->orderBy('sort_order')->first();

        abort_unless($type !== null, 500, __('Communication types have not been configured.'));

        return $type;
    }

    private function summaryBlockType(): CommunicationBlockType
    {
        $type = CommunicationBlockType::query()
            ->active()
            ->where('slug', CommunicationBlockType::SUMMARY)
            ->first();

        abort_unless($type !== null, 500, __('Communication block types have not been configured.'));

        return $type;
    }

    private function defaultAdditionalBlockType(): ?CommunicationBlockType
    {
        return $this->blockTypes->firstWhere('slug', '!=', CommunicationBlockType::SUMMARY)
            ?? $this->blockTypes->first();
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

    private function normalizedContactPersonName(): ?string
    {
        $name = Str::of($this->contactPersonName)->squish()->toString();

        return $name === '' ? null : $name;
    }

    private function parsedContactAt(): ?Carbon
    {
        try {
            return Carbon::parse($this->contactAt);
        } catch (Throwable) {
            return null;
        }
    }

    private function closeLogFlyout(): void
    {
        if ($this->logId === null) {
            $this->resetLogState();

            return;
        }

        $this->autosaveLog();
        $this->resetLogState();

        $this->dispatch('communication-log-saved');
    }

    private function resetLogState(): void
    {
        $this->logId = null;
        $this->editingSubmittedLog = false;
        $this->contactAt = '';
        $this->communicationTypeId = null;
        $this->contactPersonName = '';
        $this->blocks = [];
        $this->lastAutosavedAt = null;
    }
};
?>

<div>
    @can('create', \App\Models\CustomerCommunicationLog::class)
        @if ($splitTrigger)
            <flux:button.group>
                <flux:button
                    type="button"
                    :size="$triggerSize"
                    :variant="$triggerVariant ?: null"
                    :icon="$triggerIcon"
                    wire:click="open"
                >
                    {{ __($triggerLabel) }}
                </flux:button>
                <flux:button
                    type="button"
                    :size="$triggerSize"
                    :variant="$triggerVariant ?: null"
                    icon="plus"
                    wire:click="open"
                    :aria-label="__('Add log entry')"
                />
            </flux:button.group>
        @else
            <flux:button
                type="button"
                :size="$triggerSize"
                :variant="$triggerVariant ?: null"
                :icon="$triggerIcon"
                wire:click="open"
            >
                {{ __($triggerLabel) }}
            </flux:button>
        @endif
    @endcan

    @if ($showLogFlyout || $logId)
        <flux:modal wire:model.self="showLogFlyout" flyout variant="floating" class="md:w-2xl">
            <form wire:submit="submit" class="space-y-6">
                <div class="space-y-2">
                    <flux:heading size="lg">{{ $this->flyoutHeading() }}</flux:heading>
                    <flux:text>{{ $this->customerName() }}</flux:text>
                    @if ($lastAutosavedAt)
                        <flux:text>{{ $this->savedMessage() }}</flux:text>
                    @endif
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <flux:input
                        wire:model.live.debounce.750ms="contactAt"
                        :label="__('Contact date and time')"
                        type="datetime-local"
                        required
                    />

                    <flux:select wire:model.live="communicationTypeId" :label="__('Communication type')" required>
                        @foreach ($this->communicationTypes as $type)
                            <flux:select.option value="{{ $type->id }}">{{ $type->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>

                <div class="space-y-2">
                    <flux:input
                        wire:model.live.debounce.750ms="contactPersonName"
                        :label="__('Contact person')"
                        type="text"
                        autocomplete="off"
                    />

                    @if ($this->contactSuggestions !== [])
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->contactSuggestions as $suggestion)
                                <flux:button size="xs" type="button" wire:click="selectContactSuggestion(@js($suggestion))" class="whitespace-nowrap">
                                    {{ $suggestion }}
                                </flux:button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="space-y-4">
                    <div class="flex items-center justify-between gap-3">
                        <flux:heading size="md">{{ __('Notes') }}</flux:heading>
                        <flux:button.group>
                            <flux:button size="sm" type="button" wire:click="addBlock" icon="plus">{{ __('Add') }}</flux:button>
                            <flux:button size="sm" type="button" icon="sparkles" disabled>{{ __('Summary') }}</flux:button>
                        </flux:button.group>
                    </div>

                    @foreach ($blocks as $index => $block)
                        <div wire:key="communication-block-{{ $index }}-{{ $block['id'] ?? 'new' }}" class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                @if ($this->isSummaryBlock($block))
                                    <flux:badge color="blue">{{ $this->blockTypeName($block['communication_block_type_id']) }}</flux:badge>
                                @else
                                    <flux:select wire:model.live="blocks.{{ $index }}.communication_block_type_id" size="sm" class="sm:max-w-56" :aria-label="__('Note type')">
                                        @foreach ($this->blockTypes as $type)
                                            @if ($type->slug !== \App\Models\CommunicationBlockType::SUMMARY)
                                                <flux:select.option value="{{ $type->id }}">{{ $type->name }}</flux:select.option>
                                            @endif
                                        @endforeach
                                    </flux:select>

                                    <flux:button size="sm" type="button" variant="ghost" icon="trash" wire:click="removeBlock({{ $index }})" :aria-label="__('Remove note')" />
                                @endif
                            </div>

                            <flux:textarea
                                wire:model.live.debounce.1000ms="blocks.{{ $index }}.body"
                                rows="{{ $this->isSummaryBlock($block) ? 5 : 4 }}"
                                :label="$this->isSummaryBlock($block) ? __('Summary') : $this->blockTypeName($block['communication_block_type_id'])"
                            />
                        </div>
                    @endforeach
                </div>

                <div class="flex justify-end gap-2">
                    <flux:button type="button" variant="filled" wire:click="close">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary" icon="check">
                        {{ $this->submitButtonLabel() }}
                    </flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
