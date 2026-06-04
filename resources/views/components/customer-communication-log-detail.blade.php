@props([
    'log',
    'blocks',
])

@php
    $timezone = auth()->user()?->timezone;
    $timezone = is_string($timezone) && in_array($timezone, timezone_identifiers_list(), true)
        ? $timezone
        : (string) config('app.timezone', 'UTC');

    $contactAtLabel = $log->contact_at instanceof \Carbon\CarbonInterface
        ? $log->contact_at->copy()->timezone($timezone)->format('M j, g:i A')
        : __('N/A');

    $communicationTypeBadgeColor = match ($log->communicationType?->slug) {
        'phone' => 'green',
        'email' => 'blue',
        'text' => 'sky',
        'visit' => 'amber',
        default => 'zinc',
    };

    $statusBadgeColor = match ($log->status) {
        \App\Models\CustomerCommunicationLog::STATUS_DRAFT => 'zinc',
        \App\Models\CustomerCommunicationLog::STATUS_UPDATE_REQUESTED => 'red',
        default => 'emerald',
    };

    $statusLabel = match ($log->status) {
        \App\Models\CustomerCommunicationLog::STATUS_DRAFT => __('Draft'),
        \App\Models\CustomerCommunicationLog::STATUS_UPDATE_REQUESTED => __('Update requested'),
        default => __('Submitted'),
    };

    $blockTypeBadgeColor = fn (?string $slug): string => match ($slug) {
        \App\Models\CommunicationBlockType::SUMMARY => 'blue',
        \App\Models\CommunicationBlockType::UPDATE => 'red',
        'suggestion' => 'purple',
        'warranty' => 'amber',
        'complaint' => 'red',
        'assistance' => 'emerald',
        default => 'zinc',
    };

    $requestLog = $log->updateRequest;
    $updateResponses = $log->relationLoaded('updateResponses') ? $log->updateResponses : collect();
    $isUpdateToCurrentUserRequest = $requestLog?->update_requested_by_user_id === auth()->id();
@endphp

<div class="space-y-6">
    <div class="space-y-3">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="space-y-1">
                <flux:heading size="lg">{{ __('Communication Log') }}</flux:heading>
                <flux:text>{{ $contactAtLabel }}</flux:text>
            </div>

            <div class="flex flex-wrap gap-2">
                @if ($log->requires_follow_up)
                    <flux:badge size="sm" color="amber" icon="flag">{{ __('Follow-up') }}</flux:badge>
                @endif
                @if ($isUpdateToCurrentUserRequest)
                    <flux:badge size="sm" color="red" icon="exclamation-circle">{{ __('Update to your request') }}</flux:badge>
                @endif
                <flux:badge size="sm" color="{{ $communicationTypeBadgeColor }}">
                    {{ $log->communicationType?->name ?? __('Unknown') }}
                </flux:badge>
                <flux:badge size="sm" color="{{ $statusBadgeColor }}">
                    {{ $statusLabel }}
                </flux:badge>
            </div>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Contact person') }}</dt>
                <dd class="text-zinc-900 dark:text-zinc-100">{{ $log->contact_person_name ?: __('N/A') }}</dd>
            </div>
            <div>
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Logged by') }}</dt>
                <dd class="text-zinc-900 dark:text-zinc-100">{{ $log->user?->name ?? __('Unknown') }}</dd>
            </div>
        </dl>

        @if ($requestLog)
            <div class="flex flex-col gap-2 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-900 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-100 sm:flex-row sm:items-center sm:justify-between">
                <span>
                    {{ __('Provides update for requested log from :date.', [
                        'date' => $requestLog->contact_at instanceof \Carbon\CarbonInterface
                            ? $requestLog->contact_at->copy()->timezone($timezone)->format('M j, g:i A')
                            : __('unknown date'),
                    ]) }}
                </span>
                <flux:button size="xs" type="button" variant="ghost" icon="arrow-top-right-on-square" wire:click="viewLog('{{ $requestLog->id }}')">
                    {{ __('View Original Log') }}
                </flux:button>
            </div>
        @elseif ($updateResponses->isNotEmpty())
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-100">
                {{ trans_choice(':count update has been provided for this request.|:count updates have been provided for this request.', $updateResponses->count(), ['count' => $updateResponses->count()]) }}
            </div>
        @endif
    </div>

    <div class="space-y-4">
        @foreach ($blocks as $block)
            <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-white/10">
                <flux:badge size="sm" color="{{ $blockTypeBadgeColor($block->blockType?->slug) }}">
                    {{ $block->blockType?->name ?? __('Note') }}
                </flux:badge>
                <div class="whitespace-pre-wrap text-sm leading-6 text-zinc-900 dark:text-zinc-100">{{ trim((string) $block->body) !== '' ? $block->body : __('No content') }}</div>
            </div>
        @endforeach
    </div>

    {{ $actions ?? '' }}
</div>
