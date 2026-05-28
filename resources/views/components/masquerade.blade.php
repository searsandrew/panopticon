<?php

use Livewire\Component;

new class extends Component
{
    public ?int $netSuiteId;

    public function mount(): void
    {
        $this->netSuiteId = Auth::user()->netsuite_user_id ?? null;
    }

    public function updatedNetSuiteId()
    {
        Auth::user()->netsuite_user_id = $this->netSuiteId;
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
        <flux:menu.radio.group wire:model.live="netSuiteId">
            <flux:menu.radio value="513">{{ __('Andrew Sears') }}</flux:menu.radio>
            <flux:menu.radio value="1562">{{ __('AVR Associates') }}</flux:menu.radio>
            <flux:menu.radio value="736">{{ __('J&P HVAC Sales') }}</flux:menu.radio>
            <flux:menu.radio value="1439">{{ __('Legacy Sales') }}</flux:menu.radio>
            <flux:menu.radio value="839">{{ __('RCI Westek') }}</flux:menu.radio>
            <flux:menu.radio value="1427">{{ __('Reacond Associates') }}</flux:menu.radio>
            <flux:menu.radio value="959">{{ __('Steinmetz & Associates') }}</flux:menu.radio>
            <flux:menu.radio value="1895">{{ __('Tiffany Boers') }}</flux:menu.radio>
            <flux:menu.radio value="2214">{{ __('Tom Ruggles') }}</flux:menu.radio>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
