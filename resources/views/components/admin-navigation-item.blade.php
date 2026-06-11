<?php

use App\Models\CustomerCommunicationLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public string $surface = 'navbar';

    #[On('admin-unread-log-count-updated')]
    #[On('communication-log-saved')]
    public function refreshUnreadLogCount(): void
    {
        unset($this->unreadLogCount);
    }

    #[Computed]
    public function unreadLogCount(): int
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            return 0;
        }

        return CustomerCommunicationLog::query()
            ->visibleToUsers()
            ->whereNotNull('submitted_at')
            ->whereDoesntHave('readByUsers', fn (Builder $query): Builder => $query->where('users.id', $user->id))
            ->count();
    }
};
?>

<div class="contents" data-admin-navigation-item>
    @if ($surface === 'sidebar')
        <flux:sidebar.item
            icon="key"
            :href="route('admin.index')"
            badge="{{ $this->unreadLogCount }}"
            :current="request()->routeIs('admin.*')"
            wire:navigate
            wire:poll.30s
        >
            {{ __('Admin') }}
        </flux:sidebar.item>
    @else
        <flux:navbar.item
            icon="key"
            :href="route('admin.index')"
            badge="{{ $this->unreadLogCount }}"
            :current="request()->routeIs('admin.*')"
            wire:navigate
            wire:poll.30s
        >
            {{ __('Admin') }}
        </flux:navbar.item>
    @endif
</div>
