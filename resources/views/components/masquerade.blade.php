<?php

use App\Services\NetSuite\NetSuiteSalesRepRepository;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public ?int $netSuiteId;

    /**
     * @var array<int, array{id: int, name: string, email: string|null}>
     */
    public array $salesReps = [];

    public function mount(): void
    {
        $this->netSuiteId = Auth::user()->netsuite_user_id ?? null;
        $this->salesReps = app(NetSuiteSalesRepRepository::class)->active();
    }

    public function updatedNetSuiteId()
    {
        Auth::user()->netsuite_user_id = $this->netSuiteId;
        Auth::user()->netsuite_managed_sales_rep_ids = [];
        Auth::user()->save();

        return redirect()->route('dashboard');
    }
};
?>

<flux:dropdown>
    <flux:button
        class="h-10 cursor-pointer max-lg:hidden [&>div>svg]:size-5"
        variant="subtle"
        icon="fa-masks-theater"
        :label="__('Masquerade')"
    />
    <flux:menu>
        @if ($salesReps === [])
            <flux:menu.item disabled>{{ __('No sales reps found') }}</flux:menu.item>
        @else
            <flux:menu.radio.group wire:model.live="netSuiteId">
                @foreach ($salesReps as $salesRep)
                    <flux:menu.radio value="{{ $salesRep['id'] }}">
                        {{ $salesRep['name'] }}
                    </flux:menu.radio>
                @endforeach
            </flux:menu.radio.group>
        @endif
    </flux:menu>
</flux:dropdown>
